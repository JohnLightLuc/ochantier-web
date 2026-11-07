<?php

namespace App\Support\Admin;

use App\Enums\AttendanceAnomalyStatus;
use App\Enums\ClaimStatus;
use App\Enums\DisputeStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RatingModerationStatus;
use App\Enums\RatingStatus;
use App\Enums\RefundStatus;
use App\Enums\ReplacementStatus;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SuggestionStatus;
use App\Enums\WorkerPayoutBatchStatus;
use App\Models\Admin;
use App\Models\AttendanceAnomaly;
use App\Models\Claim;
use App\Models\Dispute;
use App\Models\PaymentTransaction;
use App\Models\PromoterProfile;
use App\Models\Rating;
use App\Models\Refund;
use App\Models\Replacement;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Suggestion;
use App\Models\Worker;
use App\Models\WorkerPayoutBatch;
use App\Support\ClaimFiling;
use App\Support\Matching\MatchingRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord : ce qui se passe et ce qui attend une action. Chaque bloc n’apparaît que si l’administrateur a le
 * droit de lire le module d’où il vient.
 */
class DashboardOverview
{
    private const POINTS = 7;

    private const WAITING_WORKERS = ['soumis', 'en_controle'];

    private const WAITING_PROMOTERS = ['soumis', 'en_controle'];

    public function __construct(private PaymentDirectory $payments, private SiteDirectory $sites) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Admin $admin, array $filters): array
    {
        $can = fn (string $permission): bool => $admin->hasPermission($permission);
        $priorities = $this->priorities($admin);

        return [
            'kpis' => $this->kpis($admin),
            'activity' => $can('sites.read') ? $this->activity($filters['activity'] ?? '7j', $filters['from'] ?? null, $filters['to'] ?? null) : null,
            'priorities' => $priorities,
            'recent_sites' => $can('sites.read') ? $this->recentSites() : [],
            'site_distribution' => $can('sites.read') ? $this->distribution() : null,
            'matching' => $can('matching.read') ? $this->matching() : null,
            'finance' => $can('payments.read') ? $this->finance($filters['finance'] ?? '7j') : null,
            'feed' => $this->feed($admin),
        ];
    }

    /**
     * Compteurs de la barre latérale.
     *
     * @return array<string, int>
     */
    public function badges(Admin $admin): array
    {
        $queues = collect($this->priorities($admin));
        $count = fn (string $key): int => (int) $queues->where('key', $key)->sum('count');

        return array_filter([
            'affectations' => $count('replacements'),
            'pointages' => $count('anomalies'),
            'paiements' => $count('batches') + $count('refunds'),
            'abonnements' => $admin->hasPermission('subscriptions.read') ? Subscription::query()->where('status', SubscriptionStatus::EnRetard)->count() : 0,
            'avis' => $count('ratings') + $count('claims') + $count('disputes'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kpis(Admin $admin): array
    {
        return array_values(array_filter([
            $admin->hasPermission('sites.read') ? ['key' => 'sites', 'value' => Site::query()->whereIn('status', SiteDirectory::ACTIVE)->count()] : null,
            $admin->hasPermission('workers.read') ? ['key' => 'workers', 'value' => Worker::query()->where('verification_status', 'valide')->where('availability_status', 'disponible')->count()] : null,
            $admin->hasPermission('missions.read') ? ['key' => 'replacements', 'value' => Replacement::query()->whereIn('status', [ReplacementStatus::Signale, ReplacementStatus::Recherche])->count()] : null,
            $admin->hasPermission('payments.read') ? ['key' => 'receivables', 'value' => $this->payments->scheduleCounts()['open_amount']] : null,
        ]));
    }

    /**
     * Files d’attente non vides, les plus urgentes d’abord.
     *
     * @return list<array{key: string, count: int, priority: string}>
     */
    public function priorities(Admin $admin): array
    {
        $rows = [
            ['replacements', 'missions.read', 'Urgent', fn () => Replacement::query()->whereIn('status', [ReplacementStatus::Signale, ReplacementStatus::Recherche])->count()],
            ['claims', 'disputes.read', 'Urgent', fn () => Claim::query()->whereIn('status', ClaimFiling::OPEN)->where('due_at', '<', now())->count()],
            ['batches', 'payouts.read', 'Élevé', fn () => WorkerPayoutBatch::query()->where('status', WorkerPayoutBatchStatus::AValider)->count()],
            ['refunds', 'payments.read', 'Élevé', fn () => Refund::query()->whereIn('status', [RefundStatus::Demande, RefundStatus::Echoue])->count()],
            ['workers', 'workers.read', 'Élevé', fn () => Worker::query()->whereIn('verification_status', self::WAITING_WORKERS)->count()],
            ['promoters', 'promoters.read', 'Élevé', fn () => PromoterProfile::query()->whereIn('verification_status', self::WAITING_PROMOTERS)->count()],
            ['anomalies', 'attendance.read', 'Élevé', fn () => AttendanceAnomaly::query()->whereIn('status', [AttendanceAnomalyStatus::Ouverte, AttendanceAnomalyStatus::EnTraitement])->count()],
            ['disputes', 'disputes.read', 'Moyen', fn () => Dispute::query()->whereIn('status', DisputeDirectory::OPEN)->count()],
            ['ratings', 'ratings.read', 'Moyen', fn () => Rating::query()->where('status', RatingStatus::Publie)->where('moderation_status', RatingModerationStatus::Signale)->count()],
        ];

        return collect($rows)
            ->filter(fn (array $row) => $admin->hasPermission($row[1]))
            ->map(fn (array $row) => ['key' => $row[0], 'count' => (int) ($row[3])(), 'priority' => $row[2]])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * Chantiers actifs à chaque date, et chantiers soumis depuis la date précédente.
     *
     * @return array<string, mixed>
     */
    private function activity(string $range, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::today();
        [$start, $end] = match ($range) {
            '30j' => [$today->subDays(29), $today],
            '3m' => [$today->subMonthsNoOverflow(3), $today],
            'custom' => [CarbonImmutable::parse($from ?? $today->subDays(6)), CarbonImmutable::parse($to ?? $today)],
            default => [$today->subDays(6), $today],
        };

        $points = $this->points($start, $end);
        $previous = $start->subDay();
        $rows = [];

        foreach ($points as $date) {
            $rows[] = [
                'date' => $date->toDateString(),
                'active' => Site::query()->whereNotIn('status', [SiteStatus::Brouillon, SiteStatus::Annule])->whereDate('planned_start_date', '<=', $date)->whereDate('planned_end_date', '>=', $date)->count(),
                'created' => Site::query()->whereBetween('submitted_at', [$previous->addDay()->startOfDay(), $date->endOfDay()])->count(),
            ];
            $previous = $date;
        }

        return ['range' => $range, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'points' => $rows];
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function points(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = (int) $start->diffInDays($end);
        $count = min(self::POINTS, $days + 1);

        return collect(range(0, $count - 1))->map(fn (int $i) => $start->addDays($count > 1 ? (int) round($days * $i / ($count - 1)) : 0))->unique(fn (CarbonImmutable $date) => $date->toDateString())->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentSites(): array
    {
        $today = CarbonImmutable::today();

        return $this->sites->query(['sort' => 'submitted_at', 'direction' => 'desc'])
            ->whereIn('sites.status', SiteDirectory::ACTIVE)
            ->limit(4)
            ->get()
            ->map(function (Site $site) use ($today): array {
                $start = $site->planned_start_date;
                $end = $site->planned_end_date;
                $span = $start && $end ? max(1, (int) $start->diffInDays($end)) : null;
                $user = $site->promoterProfile?->user;

                return [
                    'id' => $site->id,
                    'name' => $site->label,
                    'promoter' => ['id' => $site->promoter_profile_id, 'name' => $site->organization?->trade_name ?: $site->organization?->legal_name ?: trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: 'Promoteur'],
                    'location' => $site->commune?->name,
                    'elapsed' => $span ? (int) min(100, max(0, round($start->diffInDays($today, false) / $span * 100))) : 0,
                    'workers' => ['assigned' => (int) $site->assigned_workers, 'required' => (int) $site->required_workers],
                    'status' => ['value' => $site->status->value, 'label' => $site->status->label()],
                    'late' => $end !== null && $end->lessThan($today),
                    'start' => $start?->toDateString(),
                    'end' => $end?->toDateString(),
                    'type' => $site->constructionType?->label,
                ];
            })->all();
    }

    /**
     * @return array{running: int, late: int, suspended: int}
     */
    private function distribution(): array
    {
        $active = Site::query()->whereIn('status', SiteDirectory::ACTIVE);
        $late = (clone $active)->whereDate('planned_end_date', '<', today())->count();

        return [
            'running' => (clone $active)->where('status', SiteStatus::EnCours)->whereDate('planned_end_date', '>=', today())->count(),
            'late' => $late,
            'suspended' => (clone $active)->where('status', SiteStatus::Suspendu)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matching(): array
    {
        $open = DB::table('offers')->whereIn('status', array_map(fn (OfferStatus $status) => $status->value, MatchingRunner::OPEN_OFFERS))->count();
        $current = Suggestion::query()->whereIn('status', [SuggestionStatus::Suggere, SuggestionStatus::Contacte, SuggestionStatus::Accepte]);
        $profiles = (clone $current)->with(['worker.trades', 'worker.residenceCommune', 'matchingRun.need.site'])->orderByDesc('score')->limit(5)->get();

        return [
            'pending' => $open,
            'relevance' => (int) round((float) (clone $current)->avg('score')),
            'profiles' => $profiles->map(fn (Suggestion $suggestion) => [
                'name' => trim($suggestion->worker->first_name.' '.$suggestion->worker->last_name),
                'worker_id' => $suggestion->worker_id,
                'site' => $suggestion->matchingRun?->need?->site?->label,
                'score' => (int) round((float) $suggestion->score),
                'trade' => $suggestion->worker->trades->firstWhere('pivot.is_primary', true)?->label ?? $suggestion->worker->trades->first()?->label,
                'experience_years' => $suggestion->worker->trades->max('pivot.years_experience'),
                'daily_rate' => $suggestion->worker->estimated_daily_rate,
                'availability' => $suggestion->worker->availability_status?->label(),
                'rating' => $suggestion->worker->rating_avg !== null ? (float) $suggestion->worker->rating_avg : null,
                'location' => $suggestion->worker->residenceCommune?->name,
                'verification' => $suggestion->worker->verification_status?->label(),
            ])->all(),
        ];
    }

    /**
     * Argent reçu et décaissé, par tranche. « auj » découpe la journée par heure.
     *
     * @return array<string, mixed>
     */
    private function finance(string $range): array
    {
        $now = CarbonImmutable::now();
        $edges = match ($range) {
            'auj' => collect(range(0, self::POINTS))->map(fn (int $i) => $now->startOfDay()->addHours(8 + $i * 2)),
            '30j' => collect(range(0, self::POINTS))->map(fn (int $i) => $now->subDays(30)->addDays((int) round(30 * $i / self::POINTS))),
            '3m' => collect(range(0, self::POINTS))->map(fn (int $i) => $now->subDays(91)->addDays(13 * $i)),
            default => collect(range(0, self::POINTS))->map(fn (int $i) => $now->startOfDay()->subDays(6 - $i)),
        };

        $sum = fn (array $purposes, CarbonImmutable $from, CarbonImmutable $to): int => (int) PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::Reussie)
            ->whereIn('purpose', $purposes)
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<', $to)
            ->sum('amount');
        $in = [PaymentTransactionPurpose::Acompte, PaymentTransactionPurpose::Echeance, PaymentTransactionPurpose::Abonnement];
        $out = [PaymentTransactionPurpose::ReversementOuvrier, PaymentTransactionPurpose::GainCommercial, PaymentTransactionPurpose::Remboursement];
        $rows = [];

        foreach ($edges->sliding(2) as $pair) {
            [$from, $to] = $pair->values()->all();
            $rows[] = ['from' => $from->toIso8601String(), 'collected' => $sum($in, $from, $to), 'paid_out' => $sum($out, $from, $to)];
        }

        return ['range' => $range, 'points' => $rows, 'pending' => $this->payments->scheduleCounts()['open_amount']];
    }

    /**
     * Derniers événements, selon ce que l’administrateur peut voir.
     *
     * @return list<array<string, mixed>>
     */
    private function feed(Admin $admin): array
    {
        $entries = new Collection;

        if ($admin->hasPermission('sites.read')) {
            $entries = $entries->concat(Site::query()->whereNotNull('submitted_at')->latest('submitted_at')->limit(5)->get()->map(fn (Site $site) => ['kind' => 'site', 'id' => $site->id, 'target' => $site->label, 'at' => $site->submitted_at]));
        }

        if ($admin->hasPermission('workers.read')) {
            $entries = $entries->concat(Worker::query()->where('verification_status', 'valide')->whereNotNull('validated_at')->latest('validated_at')->limit(5)->get()->map(fn (Worker $worker) => ['kind' => 'worker', 'id' => $worker->id, 'target' => trim($worker->first_name.' '.$worker->last_name), 'at' => $worker->validated_at]));
        }

        if ($admin->hasPermission('payments.read')) {
            $entries = $entries->concat(PaymentTransaction::query()->where('direction', PaymentTransactionDirection::Encaissement)->whereIn('status', [PaymentTransactionStatus::Reussie, PaymentTransactionStatus::Echouee])->latest('initiated_at')->limit(5)->get()->map(fn (PaymentTransaction $tx) => ['kind' => $tx->status === PaymentTransactionStatus::Reussie ? 'payment' : 'payment_failed', 'id' => $tx->id, 'target' => $tx->reference, 'amount' => $tx->amount, 'at' => $tx->completed_at ?? $tx->initiated_at]));
        }

        if ($admin->hasPermission('disputes.read')) {
            $entries = $entries->concat(Dispute::query()->where('status', '!=', DisputeStatus::Clos)->latest()->limit(5)->get()->map(fn (Dispute $dispute) => ['kind' => 'dispute', 'id' => $dispute->id, 'target' => $dispute->reference, 'at' => $dispute->created_at]));
            $entries = $entries->concat(Claim::query()->where('status', ClaimStatus::Ouverte)->latest()->limit(5)->get()->map(fn (Claim $claim) => ['kind' => 'claim', 'id' => $claim->id, 'target' => $claim->reference.' · '.$claim->subject, 'at' => $claim->created_at]));
        }

        return $entries->sortByDesc(fn (array $entry) => $entry['at'])->take(10)->values()->map(fn (array $entry) => [...$entry, 'at' => $entry['at']?->toIso8601String()])->all();
    }
}
