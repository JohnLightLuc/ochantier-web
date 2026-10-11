<?php

namespace App\Http\Controllers;

use App\Models\Promoter;
use App\Models\Subscription;
use App\Models\SubscriptionActivity;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Abonnements des promoteurs : liste des abonnés, formules proposées et fiche d’un abonnement.
 */
class SubscriptionController extends Controller
{
    private const PER_PAGE = 8;

    /**
     * @return Collection<int, Subscription>
     */
    public static function subscriptions(): Collection
    {
        return Subscription::with('promoter', 'plan', 'pendingPlan', 'users')->get()->sortBy(fn (Subscription $s): string => Str::ascii($s->promoter->name))->values();
    }

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    public static function plans(): Collection
    {
        return SubscriptionPlan::query()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Indicateurs de l’en-tête : abonnés payants, revenu mensuel récurrent, conversion, impayés.
     *
     * @param  Collection<int, Subscription>  $all
     * @return array<int, array<string, mixed>>
     */
    private function kpis(Collection $all): array
    {
        $paid = $all->filter(fn (Subscription $s): bool => $s->plan_key !== SubscriptionPlan::SOLO && in_array($s->status, ['Actif', 'Impayé'], true));
        $mrr = (int) $paid->where('status', 'Actif')->sum(fn (Subscription $s): int => $s->plan->price);
        $unpaid = $all->where('status', 'Impayé')->count();

        return [
            ['label' => 'Abonnés payants', 'value' => (string) $paid->count(), 'unit' => "sur {$all->count()}", 'icon' => 'workspace_premium', 'tone' => 'g'],
            ['label' => 'Revenu mensuel récurrent', 'value' => number_format($mrr, 0, ',', ' '), 'unit' => 'FCFA', 'icon' => 'payments', 'tone' => 'b'],
            ['label' => 'Taux de conversion', 'value' => (int) round($paid->count() / max(1, $all->count()) * 100).' %', 'icon' => 'trending_up', 'tone' => 'a', 'hint' => 'Promoteurs passés d’une formule Solo à une formule payante'],
            ['label' => 'Impayés', 'value' => (string) $unpaid, 'icon' => 'error', 'tone' => 'r', 'highlight' => $unpaid > 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function planData(SubscriptionPlan $p, ?Collection $subscriptions = null): array
    {
        $data = ['key' => $p->key, 'name' => $p->name, 'subtitle' => $p->subtitle, 'description' => $p->description, 'price' => $p->price, 'priceLabel' => $p->priceLabel(), 'period' => $p->period, 'maxUsers' => $p->max_users, 'features' => $p->features, 'popular' => $p->popular, 'solo' => $p->isSolo()];
        if ($subscriptions) {
            $live = $subscriptions->filter(fn (Subscription $s): bool => $s->plan_key === $p->key && $s->isLive());
            $data += [
                'subscribers' => $live->count(),
                'mrr' => $p->price ? $live->where('status', 'Actif')->count() * $p->price : null,
                'averageUsers' => str_replace('.', ',', (string) round($live->sum(fn (Subscription $s): int => $s->users->count()) / max(1, $live->count()), 1)),
            ];
        }

        return $data;
    }

    public function index(Request $request): Response
    {
        $all = self::subscriptions();
        $plans = self::plans();
        $q = Str::lower(trim((string) $request->query('q', '')));
        $plan = (string) $request->query('formule', '');
        $status = (string) $request->query('statut', '');
        $rows = $all->filter(fn (Subscription $s): bool => (! $plan || $s->plan_key === $plan) && (! $status || $s->status === $status) && (! $q || str_contains(Str::lower($s->promoter->name.' '.$s->users->firstWhere('owner', true)?->name), $q)))->values();
        $last = max(1, (int) ceil($rows->count() / self::PER_PAGE));
        $page = min(max(1, (int) $request->query('page', 1)), $last);
        $today = Subscription::referenceDate();

        return Inertia::render('Subscriptions/Index', [
            'tab' => $request->query('onglet') === 'formules' ? 'formules' : 'abonnes',
            'kpis' => $this->kpis($all),
            'filters' => array_filter(['q' => $request->query('q'), 'formule' => $plan, 'statut' => $status]),
            'statuses' => Subscription::STATUSES,
            'plans' => $plans->map(fn (SubscriptionPlan $p): array => self::planData($p, $all))->all(),
            'counts' => ['subscriptions' => $all->count(), 'plans' => $plans->count()],
            'subscriptions' => [
                'data' => $rows->forPage($page, self::PER_PAGE)->values()->map(function (Subscription $s) use ($today): array {
                    $users = $s->users->count();

                    return [
                        'slug' => $s->promoter->slug, 'promoter' => $s->promoter->name, 'owner' => $s->users->firstWhere('owner', true)?->name ?? $s->promoter->name,
                        'plan' => ['key' => $s->plan_key, 'name' => $s->plan->name], 'users' => $users, 'maxUsers' => $s->plan->max_users,
                        'amount' => $s->plan->priceLabel(), 'period' => $s->plan->period, 'status' => ['label' => $s->status, 'tone' => Subscription::STATUSES[$s->status]],
                        'nextDue' => $s->next_due?->format('Y-m-d'),
                        'note' => match (true) {
                            (bool) $s->pendingPlan => "Passage à {$s->pendingPlan->name} prévu",
                            $s->status === 'Impayé' && $s->next_due => 'En retard de '.(int) $s->next_due->diffInDays($today).' j',
                            $s->status === 'Essai' => 'Fin de l’essai',
                            default => null,
                        },
                    ];
                })->all(),
                'page' => $page, 'lastPage' => $last, 'total' => $rows->count(), 'from' => $rows->isEmpty() ? 0 : ($page - 1) * self::PER_PAGE + 1, 'to' => min($rows->count(), $page * self::PER_PAGE),
            ],
        ]);
    }

    /**
     * Export de la liste des abonnements (CSV lisible dans Excel).
     */
    public function export(): StreamedResponse
    {
        $all = self::subscriptions();

        return response()->streamDownload(function () use ($all): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Promoteur', 'Responsable', 'Formule', 'Utilisateurs', 'Montant', 'Statut', 'Abonné depuis', 'Prochaine échéance', 'Moyen de paiement'], ';');
            foreach ($all as $s) {
                fputcsv($out, [$s->promoter->name, $s->users->firstWhere('owner', true)?->name, $s->plan->name, "{$s->users->count()} / {$s->plan->max_users}", $s->plan->priceLabel(), $s->status, $s->since->format('d/m/Y'), $s->next_due?->format('d/m/Y') ?? '—', $s->payment_method ?? '—'], ';');
            }
            fclose($out);
        }, 'abonnements-'.Subscription::referenceDate()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Promoter $promoter): Response
    {
        $s = Subscription::with('plan', 'pendingPlan', 'users', 'invoices', 'activities')->where('promoter_id', $promoter->id)->first();
        abort_unless($s, 404, 'Abonnement introuvable.');
        $owner = $s->users->firstWhere('owner', true);

        return Inertia::render('Subscriptions/Show', [
            'subscription' => [
                'slug' => $promoter->slug, 'promoter' => $promoter->name, 'owner' => $owner?->name ?? $promoter->name, 'phone' => $promoter->phone ?? '—', 'email' => $promoter->email ?? '—',
                'status' => ['label' => $s->status, 'tone' => Subscription::STATUSES[$s->status]], 'statusLabel' => $s->status, 'locked' => $s->isLocked(),
                'plan' => self::planData($s->plan), 'previousPlan' => $s->previous_plan_key,
                'since' => $s->since->format('Y-m-d'), 'nextDue' => $s->next_due?->format('Y-m-d'), 'paymentMethod' => $s->payment_method,
                'pending' => $s->pendingPlan ? ['name' => $s->pendingPlan->name, 'at' => $s->pending_at?->format('Y-m-d')] : null,
            ],
            'plans' => self::plans()->map(fn (SubscriptionPlan $p): array => self::planData($p))->all(),
            'users' => $s->users->map(fn (SubscriptionUser $u): array => $u->only(['id', 'name', 'role', 'via', 'status', 'owner']))->all(),
            'invoices' => $s->invoices->map(fn (SubscriptionInvoice $i): array => ['id' => $i->id, 'reference' => $i->reference, 'date' => $i->date->format('Y-m-d'), 'label' => $i->label, 'amount' => $i->amount, 'status' => $i->status])->all(),
            'activities' => $s->activities->take(8)->map(fn (SubscriptionActivity $a): array => ['id' => $a->id, 'date' => $a->date->format('Y-m-d'), 'text' => $a->text])->values()->all(),
            'roles' => Subscription::ROLES,
            'header' => $this->header($promoter->name, "Abonnement {$s->plan->name} · ".($owner?->name ?? $promoter->name), [['Abonnements', '/abonnements'], [$promoter->name]]),
        ]);
    }
}
