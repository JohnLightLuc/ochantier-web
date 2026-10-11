<?php

namespace App\Http\Controllers;

use App\Models\Promoter;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Actions sur l’abonnement d’un promoteur : changement de formule, paiement, essai, suspension, résiliation,
 * réactivation et gestion des collaborateurs.
 */
class SubscriptionActionController extends Controller
{
    private function subscription(Promoter $promoter): Subscription
    {
        $s = Subscription::with('plan', 'users', 'invoices')->where('promoter_id', $promoter->id)->first();
        abort_unless($s, 404);

        return $s;
    }

    private static function date(?\DateTimeInterface $d): string
    {
        return $d ? $d->format('d/m/Y') : '—';
    }

    /**
     * Montée de formule : activation immédiate et prorata facturé ; descente : programmée à la prochaine échéance.
     */
    public function changePlan(Request $request, Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        $key = $request->validate(['plan' => ['required', Rule::exists('subscription_plans', 'key')]])['plan'];
        $target = SubscriptionPlan::query()->where('key', $key)->firstOrFail();
        $current = $s->plan;
        abort_if($key === $s->plan_key, 422, 'Cette formule est déjà active.');
        abort_if($s->isLocked(), 422, 'Réactivez l’abonnement avant de changer de formule.');
        $users = $s->users->count();
        abort_if($users > $target->max_users, 422, "Ce compte a {$users} utilisateurs. Retirez-en ".($users - $target->max_users)." avant de passer à {$target->name} ({$target->max_users} maximum).");
        $today = Subscription::referenceDate();

        if ($target->price > $current->price) {
            $left = $s->next_due ? max(0, (int) $today->diffInDays($s->next_due, false)) : 30;
            $prorata = $current->price ? (int) round(($target->price - $current->price) * min(30, $left) / 30 / 100) * 100 : $target->price;
            $wasSolo = $s->plan_key === SubscriptionPlan::SOLO;
            $s->update([
                'plan_key' => $key, 'pending_plan_key' => null, 'pending_at' => null, 'status' => 'Actif',
                'next_due' => $wasSolo || ! $s->next_due ? $today->addDays(30) : $s->next_due,
                'payment_method' => $s->payment_method ?? 'Orange Money',
            ]);
            $s->invoices()->create([
                'reference' => 'ABO-'.$today->format('Ym').'-'.sprintf('%04d', $s->invoices->count() + 500), 'date' => $today, 'amount' => $prorata,
                'label' => $wasSolo ? "Formule {$target->name}" : "Prorata {$current->name} → {$target->name}", 'status' => 'En attente',
            ]);
            $s->log("Formule changée : {$current->name} → {$target->name} (activation immédiate)");
            Inertia::flash('toast', "Formule {$target->name} activée pour {$promoter->name}.");

            return back();
        }

        abort_unless($s->next_due, 422, 'Aucune échéance à laquelle programmer le changement.');
        $s->update(['pending_plan_key' => $key, 'pending_at' => $s->next_due]);
        $s->log("Passage à la formule {$target->name} programmé au ".self::date($s->next_due));
        Inertia::flash('toast', 'Changement programmé au '.self::date($s->next_due).'.');

        return back();
    }

    public function cancelPending(Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->pending_plan_key, 422, 'Aucun changement programmé.');
        $s->update(['pending_plan_key' => null, 'pending_at' => null]);
        $s->log('Changement de formule programmé annulé');
        Inertia::flash('toast', 'Changement annulé.');

        return back();
    }

    public function remind(Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->status === 'Impayé', 422, 'Aucun impayé à relancer.');
        $s->log('Relance de paiement envoyée par SMS et e-mail');
        Inertia::flash('toast', 'Relance envoyée à '.($s->users->firstWhere('owner', true)?->name ?? $promoter->name).'.');

        return back();
    }

    /**
     * Paiement enregistré manuellement : la facture en échec passe « Payée », l’échéance avance d’un mois.
     */
    public function markPaid(Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->status === 'Impayé', 422, 'Aucun impayé à régler.');
        $s->invoices->firstWhere('status', 'Échouée')?->update(['status' => 'Payée']);
        $s->update(['status' => 'Actif', 'next_due' => ($s->next_due ?? Subscription::referenceDate())->addDays(30)]);
        $s->log('Paiement enregistré manuellement');
        Inertia::flash('toast', 'Paiement enregistré.');

        return back();
    }

    public function extendTrial(Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->status === 'Essai', 422, 'L’abonnement n’est pas en essai.');
        $s->update(['next_due' => ($s->next_due ?? Subscription::referenceDate())->addDays(7)]);
        $s->log('Essai prolongé jusqu’au '.self::date($s->next_due));
        Inertia::flash('toast', 'Essai prolongé jusqu’au '.self::date($s->next_due).'.');

        return back();
    }

    public function suspend(Request $request, Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->plan_key !== SubscriptionPlan::SOLO && $s->isLive(), 422, 'Seul un abonnement payant actif peut être suspendu.');
        $reason = $this->reason($request);
        $s->update(['status' => 'Suspendu']);
        $s->log("Abonnement suspendu · {$reason}");
        Inertia::flash('toast', 'Abonnement suspendu.');

        return back();
    }

    /**
     * Réactivation d’un abonnement suspendu (les collaborateurs retrouvent leur accès).
     */
    public function reactivate(Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->status === 'Suspendu', 422, 'L’abonnement n’est pas suspendu.');
        $s->update(['status' => 'Actif']);
        $s->log('Abonnement réactivé');
        Inertia::flash('toast', 'Abonnement réactivé.');

        return back();
    }

    /**
     * Résiliation : retour à la formule Solo, seul le propriétaire conserve l’accès (données des chantiers conservées).
     */
    public function terminate(Request $request, Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_if($s->plan_key === SubscriptionPlan::SOLO || $s->status === 'Résilié', 422, 'Aucun abonnement payant à résilier.');
        $reason = $this->reason($request);
        $s->users()->where('owner', false)->delete();
        $s->update(['status' => 'Résilié', 'previous_plan_key' => $s->plan_key, 'plan_key' => SubscriptionPlan::SOLO, 'next_due' => null, 'pending_plan_key' => null, 'pending_at' => null]);
        $s->log("Abonnement résilié · {$reason}");
        Inertia::flash('toast', 'Abonnement résilié. Le compte repasse en Solo.');

        return back();
    }

    /**
     * Après une résiliation : le compte redevient actif en Solo, prêt à souscrire une formule.
     */
    public function restore(Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($s->status === 'Résilié', 422, 'L’abonnement n’est pas résilié.');
        $s->update(['status' => 'Actif']);
        $s->log('Compte réactivé en formule Solo');
        Inertia::flash('toast', 'Choisissez la formule à activer.');

        return back();
    }

    public function invite(Request $request, Promoter $promoter): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_if($s->isLocked(), 422, 'Abonnement '.mb_strtolower($s->status).' : les invitations sont bloquées.');
        abort_if($s->users->count() >= $s->plan->max_users, 422, $s->plan_key === SubscriptionPlan::SOLO ? 'La formule Solo est limitée au propriétaire.' : "Limite de {$s->plan->max_users} utilisateurs atteinte.");
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'channel' => ['required', Rule::in(['SMS', 'E-mail'])],
            'role' => ['required', Rule::in(Subscription::ROLES)],
            'contact' => ['required', 'string', 'max:120'],
        ], ['name.*' => 'Le nom est obligatoire.']);
        $valid = $data['channel'] === 'E-mail' ? filter_var($data['contact'], FILTER_VALIDATE_EMAIL) : strlen(preg_replace('/\D/', '', $data['contact'])) >= 8;
        if (! $valid) {
            throw ValidationException::withMessages(['contact' => $data['channel'] === 'E-mail' ? 'Adresse e-mail invalide.' : 'Numéro invalide (8 chiffres minimum).']);
        }

        $s->users()->create(['name' => trim($data['name']), 'role' => $data['role'], 'via' => 'Invité par '.($data['channel'] === 'SMS' ? 'SMS' : 'e-mail'), 'contact' => $data['contact'], 'status' => 'Invitation envoyée']);
        $s->log('Invitation envoyée à '.trim($data['name'])." ({$data['role']})");
        Inertia::flash('toast', 'Invitation envoyée par '.($data['channel'] === 'SMS' ? 'SMS' : 'e-mail').'.');

        return back();
    }

    public function removeUser(Promoter $promoter, SubscriptionUser $user): RedirectResponse
    {
        $s = $this->subscription($promoter);
        abort_unless($user->subscription_id === $s->id, 404);
        abort_if($user->owner, 422, 'Le propriétaire ne peut pas être retiré.');
        $user->delete();
        $s->log("{$user->name} retiré du compte");
        Inertia::flash('toast', 'Accès retiré.');

        return back();
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => 'Le motif est obligatoire.'])['reason']);
    }
}
