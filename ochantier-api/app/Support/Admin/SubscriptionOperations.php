<?php

namespace App\Support\Admin;

use App\Enums\SubscriptionStatus;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur un abonnement. L’encaissement reste dans l’application Promoteur : on relance, on
 * programme une formule pour le prochain renouvellement, on suspend, on réactive ou on résilie.
 */
class SubscriptionOperations
{
    public function __construct(private PromoterSubscriptions $subscriptions, private PromoterNotifier $notifier) {}

    /**
     * @return array{remind: bool, change_plan: bool, cancel_plan_change: bool, suspend: bool, reactivate: bool, terminate: bool, resume: bool}
     */
    public function actions(Subscription $subscription): array
    {
        $current = in_array($subscription->status, PromoterSubscriptions::CURRENT, true);
        $suspended = $subscription->status === SubscriptionStatus::Suspendue;
        $resumable = $current && ! $subscription->auto_renew && $subscription->status !== SubscriptionStatus::EnRetard;

        return [
            'remind' => $current && $this->subscriptions->openInvoice($subscription) !== null,
            'change_plan' => $this->planChangeRefusal($subscription) === null,
            'cancel_plan_change' => $subscription->pending_plan_id !== null,
            'suspend' => $current,
            'reactivate' => $suspended,
            'terminate' => $suspended || ($current && $subscription->auto_renew),
            'resume' => $resumable && ! $subscription->current_period_end?->lessThan(today()),
        ];
    }

    public function remind(Subscription $subscription, Admin $admin, Request $request): void
    {
        $invoice = $this->subscriptions->openInvoice($subscription);
        $this->ensure($invoice !== null && in_array($subscription->status, PromoterSubscriptions::CURRENT, true), 'Aucune facture à relancer.');
        $this->ensure($this->notifier->invoiceReminder($invoice) > 0, 'Le promoteur a déjà été relancé aujourd’hui pour cette facture.');

        AdminAudit::record($admin, 'subscriptions', 'subscriptions.reminded', $request, $subscription, null, ['invoice_id' => $invoice->id, 'amount' => $invoice->amount]);
    }

    /**
     * Le changement s’applique à la prochaine facture : le promoteur le paie dans l’application.
     */
    public function schedulePlan(Subscription $subscription, string $code, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($subscription, $code, $admin, $request): void {
            $subscription = $this->locked($subscription);
            $this->ensure(($refusal = $this->planChangeRefusal($subscription)) === null, (string) $refusal);

            $plan = SubscriptionPlan::query()->where('code', $code)->where('is_active', true)->first();
            $this->ensure($plan !== null, 'Cette formule n’est plus proposée.');
            $this->ensure($plan->code !== PromoterSubscriptions::FREE_PLAN, 'Pour revenir à Solo, résiliez l’abonnement.');
            $this->ensure(! $plan->is($subscription->plan), 'C’est déjà la formule de cet abonnement.');

            $seats = $this->subscriptions->seatsUsed($subscription->promoterProfile);
            $this->ensure($seats <= $plan->max_users, 'Ce compte a '.$seats.' utilisateurs : la formule '.$plan->name.' en autorise '.$plan->max_users.'.');

            $effective = $subscription->current_period_end->copy()->addDay();
            $subscription->update(['pending_plan_id' => $plan->getKey(), 'pending_effective_at' => $effective]);
            $this->history($subscription, 'Passage à '.$plan->name.' programmé au '.$effective->format('d/m/Y'), $admin, $plan);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.plan_scheduled', $request, $subscription, null, ['plan' => $plan->code, 'effective_at' => $effective->toDateString()]);
            $this->notifier->subscriptionUpdated($subscription, 'Changement de formule programmé', 'Votre abonnement passera à la formule '.$plan->name.' le '.$effective->format('d/m/Y').'.');
        });
    }

    public function cancelPlanChange(Subscription $subscription, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($subscription, $admin, $request): void {
            $subscription = $this->locked($subscription);
            $plan = $subscription->pendingPlan;
            $this->ensure($plan !== null, 'Aucun changement de formule n’est programmé.');

            $subscription->update(['pending_plan_id' => null, 'pending_effective_at' => null]);
            $this->history($subscription, 'Passage à '.$plan->name.' annulé', $admin, $plan);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.plan_change_cancelled', $request, $subscription, ['plan' => $plan->code]);
            $this->notifier->subscriptionUpdated($subscription, 'Changement de formule annulé', 'Votre abonnement garde la formule '.$subscription->plan->name.'.');
        });
    }

    /**
     * Coupure immédiate : les collaborateurs perdent l’accès et le propriétaire garde son compte avec les droits Solo.
     */
    public function suspend(Subscription $subscription, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($subscription, $reason, $admin, $request): void {
            $subscription = $this->locked($subscription);
            $this->ensure(in_array($subscription->status, PromoterSubscriptions::CURRENT, true), 'Cet abonnement n’est pas en vigueur.');

            $this->subscriptions->move($subscription, SubscriptionStatus::Suspendue, $reason, $admin);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.suspended', $request, $subscription, null, ['reason' => $reason]);
            $this->notifier->subscriptionUpdated($subscription, 'Abonnement suspendu', 'Votre abonnement est suspendu. Motif : '.$reason);
        });
    }

    /**
     * Reprend l’état d’avant la suspension. Une facture ouverte repart avec un délai de grâce complet, la suspension
     * ayant empêché de la régler. La durée de la suspension n’est pas ajoutée à la période.
     */
    public function reactivate(Subscription $subscription, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($subscription, $admin, $request): void {
            $subscription = $this->locked($subscription);
            $this->ensure($subscription->status === SubscriptionStatus::Suspendue, 'Cet abonnement n’est pas suspendu.');

            $previous = $subscription->statusHistories()->where('to_status', SubscriptionStatus::Suspendue->value)->latest()->latest('id')->first()?->from_status ?? SubscriptionStatus::Active;
            $this->subscriptions->move($subscription, $previous, 'Réactivation', $admin);
            $this->subscriptions->openInvoice($subscription)?->update(['due_date' => today()]);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.reactivated', $request, $subscription, null, ['status' => $previous->value]);
            $this->notifier->subscriptionUpdated($subscription, 'Abonnement réactivé', 'Votre abonnement '.$subscription->plan->name.' est de nouveau actif.');
        });
    }

    /**
     * La période payée est honorée, puis le compte revient à Solo. Un abonnement en retard ou suspendu se termine tout
     * de suite. Contrairement à l’application, les collaborateurs n’ont pas à être retirés avant.
     */
    public function terminate(Subscription $subscription, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($subscription, $reason, $admin, $request): void {
            $subscription = $this->locked($subscription);
            $this->ensure($this->actions($subscription)['terminate'], 'Cet abonnement n’est plus à résilier.');

            $subscription = $this->subscriptions->stopRenewal($subscription, $reason, $admin);
            $ended = $subscription->status === SubscriptionStatus::Resiliee;

            if (! $ended) {
                $this->history($subscription, 'Résiliation programmée : '.$reason, $admin);
            }

            AdminAudit::record($admin, 'subscriptions', 'subscriptions.terminated', $request, $subscription, null, ['reason' => $reason, 'immediate' => $ended]);
            $this->notifier->subscriptionUpdated($subscription, 'Abonnement résilié', $ended
                ? 'Votre abonnement est résilié : votre compte revient à la formule Solo. Motif : '.$reason
                : 'Votre formule reste active jusqu’au '.$subscription->current_period_end->format('d/m/Y').', puis votre compte revient à Solo. Motif : '.$reason);
        });
    }

    /**
     * Annule une résiliation programmée, tant que la période payée n’est pas finie.
     */
    public function resume(Subscription $subscription, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($subscription, $admin, $request): void {
            $subscription = $this->locked($subscription);
            $this->ensure($this->actions($subscription)['resume'], 'Aucune résiliation à annuler.');

            $subscription->update(['auto_renew' => true, 'cancelled_at' => null, 'cancellation_reason' => null]);
            $this->history($subscription, 'Résiliation annulée', $admin);
            AdminAudit::record($admin, 'subscriptions', 'subscriptions.termination_cancelled', $request, $subscription);
            $this->notifier->subscriptionUpdated($subscription, 'Résiliation annulée', 'Votre abonnement '.$subscription->plan->name.' sera renouvelé normalement.');
        });
    }

    private function planChangeRefusal(Subscription $subscription): ?string
    {
        return match (true) {
            ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Essai], true) => 'Seul un abonnement à jour peut changer de formule.',
            ! $subscription->auto_renew => 'Cet abonnement est en cours de résiliation.',
            $subscription->current_period_end === null => 'Cet abonnement n’a pas de période en cours.',
            $this->subscriptions->openInvoice($subscription) !== null => 'Une facture est en attente : elle doit être réglée avant un changement de formule.',
            default => null,
        };
    }

    private function history(Subscription $subscription, string $reason, Admin $admin, ?SubscriptionPlan $plan = null): void
    {
        $subscription->statusHistories()->create([
            'from_status' => $subscription->status->value,
            'to_status' => $subscription->status->value,
            'from_plan_id' => $plan ? $subscription->plan_id : null,
            'to_plan_id' => $plan?->getKey(),
            'reason' => $reason,
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
        ]);
    }

    private function locked(Subscription $subscription): Subscription
    {
        return Subscription::query()->with(['plan', 'pendingPlan', 'promoterProfile.user'])->lockForUpdate()->findOrFail($subscription->getKey());
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['subscription' => $message]);
        }
    }
}
