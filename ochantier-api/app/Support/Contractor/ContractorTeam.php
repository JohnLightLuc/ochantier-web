<?php

namespace App\Support\Contractor;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\OfferType;
use App\Enums\PaymentFrequency;
use App\Enums\PriceOrigin;
use App\Enums\PricingSnapshotKind;
use App\Enums\SalaryPeriod;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\Contractor;
use App\Models\Mission;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkDay;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Media\MediaStore;
use App\Support\Promoter\WorkerCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Équipe de l’entrepreneur sur un chantier attribué (« Choisir des ouvriers », « Affectation », « Équipe »,
 * « Membre de l’équipe », « Remplacer l’ouvrier »).
 *
 * L’entrepreneur choisit des ouvriers validés du catalogue et les affecte directement, sur une période comprise dans
 * celle de son forfait, avec un rôle et un tarif journalier qu’il paie lui-même, hors plateforme. Chaque affectation
 * porte son besoin et sa mission « équipe de l’entrepreneur » (sans acompte) et attend l’accord de l’ouvrier, donné
 * depuis le Back Office. Un ouvrier ne peut pas être affecté sur deux périodes qui se chevauchent. Le promoteur voit
 * l’équipe en lecture seule.
 */
class ContractorTeam
{
    /**
     * Affectations de l’équipe en cours ou à venir.
     */
    public const LIVE = [AssignmentStatus::EnAttente, AssignmentStatus::Confirmee, AssignmentStatus::Active];

    /**
     * Motifs de remplacement proposés.
     */
    public const REPLACEMENT_REASONS = ['Absences répétées', 'Qualité du travail', 'Indisponible', 'Autre'];

    private const WORKER_WITH = ['worker.photoMedia', 'worker.workerTrades.trade', 'worker.residenceCommune', 'mission'];

    public function __construct(private WorkerCatalog $catalog, private ContractorSites $sites, private ContractorNotifier $notifier, private MediaStore $media, private ContractorSubscriptions $subscriptions) {}

    /**
     * Catalogue « Choisir des ouvriers » : ouvriers validés, disponibles d’abord, avec leur tarif net déclaré.
     * Filtres : trade_id, city_id (commune de résidence), search, max_rate (tarif net maximum), available.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function catalog(array $filters, ?Site $site = null): Collection
    {
        $team = $site ? $this->liveAssignments($site)->pluck('worker_id')->all() : [];

        return $this->catalog->query(collect($filters)->only(['trade_id', 'search', 'available'])->all())
            ->when($filters['max_rate'] ?? null, fn (Builder $query, int $max) => $query->where('estimated_daily_rate', '<=', $max))
            ->when($filters['city_id'] ?? null, fn (Builder $query, string $city) => $query->whereHas('residenceCommune', fn (Builder $query) => $query->where('city_id', $city)))
            ->limit(100)
            ->get()
            ->map(fn (Worker $worker) => $this->worker($worker, in_array($worker->id, $team, true)));
    }

    /**
     * Fiche d’un ouvrier du catalogue.
     *
     * @return array<string, mixed>
     */
    public function profile(string $id, ?Site $site = null): array
    {
        $worker = str($id)->isUuid() ? $this->catalog->query()->find($id) : null;

        abort_unless($worker, 404, 'Ouvrier introuvable.');

        $inTeam = $site && $this->liveAssignments($site)->contains('worker_id', $worker->id);

        return [
            ...$this->worker($worker, $inTeam),
            'busy_periods' => Assignment::query()->with('mission.site')
                ->where('worker_id', $worker->id)
                ->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, self::LIVE))
                ->where('planned_end_date', '>=', today())
                ->orderBy('planned_start_date')
                ->get()
                ->map(fn (Assignment $assignment) => ['start_date' => $assignment->planned_start_date->toDateString(), 'end_date' => $assignment->planned_end_date->toDateString()])
                ->all(),
        ];
    }

    /**
     * « Affecter au chantier » : une affectation par ouvrier, en attente de son accord.
     *
     * @param  list<array{worker_id: string, role: string, daily_rate: int}>  $workers
     * @return Collection<int, Assignment>
     */
    public function assign(Contractor $contractor, Site $site, User $author, CarbonImmutable $start, CarbonImmutable $end, array $workers): Collection
    {
        $this->ensureManageable($site);
        $this->ensureCapacity($contractor, $site, count($workers));
        [$first, $last] = $this->window($contractor, $site);

        if ($start->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['start_date' => 'La période ne peut pas commencer dans le passé.']);
        }

        if ($start->lt($first) || $end->gt($last)) {
            throw ValidationException::withMessages(['start_date' => 'La période doit être comprise dans les dates du chantier ('.$first->format('d/m/Y').' – '.$last->format('d/m/Y').').']);
        }

        $models = Worker::query()->whereKey(array_column($workers, 'worker_id'))->get()->keyBy('id');

        foreach ($workers as $index => $line) {
            $worker = $models->get($line['worker_id']);

            if (! $worker || $worker->verification_status?->value !== 'valide' || $worker->merged_into_worker_id) {
                throw ValidationException::withMessages(['workers.'.$index.'.worker_id' => 'Cet ouvrier n’est pas disponible au catalogue.']);
            }

            if ($conflict = $this->conflict($worker, $start, $end)) {
                throw ValidationException::withMessages(['workers.'.$index.'.worker_id' => trim($worker->first_name.' '.$worker->last_name).' est déjà affecté du '
                    .$conflict->planned_start_date->format('d/m/Y').' au '.$conflict->planned_end_date->format('d/m/Y').' ('.$conflict->mission->site->label.').']);
            }
        }

        return DB::transaction(fn () => collect($workers)->map(fn (array $line) => $this->createAssignment(
            $contractor, $site, $author, $models->get($line['worker_id']), trim($line['role']), (int) $line['daily_rate'], $start, $end,
        )));
    }

    /**
     * Onglet « Équipe » : ouvriers en cours ou à venir (avec l’accord en attente), coût journalier et prévisionnel,
     * puis l’historique (remplacés, annulés, refusés, terminés).
     *
     * @return array<string, mixed>
     */
    public function team(Contractor $contractor, Site $site): array
    {
        $all = Assignment::query()->with(self::WORKER_WITH)
            ->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey())->where('contractor_id', $contractor->getKey()))
            ->orderBy('planned_start_date')
            ->get();
        $live = $all->filter(fn (Assignment $assignment) => in_array($assignment->status, self::LIVE, true))->values();
        [$first, $last] = $this->window($contractor, $site);

        return [
            'count' => $live->count(),
            'count_label' => $live->count().' ouvrier'.($live->count() > 1 ? 's' : ''),
            'daily_cost' => (int) $live->sum('salary_amount'),
            'planned_cost' => (int) $live->sum(fn (Assignment $assignment) => $this->days($assignment) * (int) $assignment->salary_amount),
            'pending_agreements' => $live->where('status', AssignmentStatus::EnAttente)->count(),
            'members' => $live->map(fn (Assignment $assignment) => $this->member($assignment))->all(),
            'history' => $all->reject(fn (Assignment $assignment) => in_array($assignment->status, self::LIVE, true))->sortByDesc('planned_end_date')->map(fn (Assignment $assignment) => $this->member($assignment))->values()->all(),
            'window' => ['start_date' => $first->toDateString(), 'end_date' => $last->toDateString()],
            'can_manage' => $this->manageable($site),
            'subscription' => [
                'subscribed' => $this->subscriptions->isSubscribed($contractor),
                'seats' => $this->subscriptions->seats($contractor),
                'available_seats' => max(0, $this->subscriptions->seats($contractor) - $live->count()),
                'lock_message' => $this->subscriptions->isSubscribed($contractor) ? null : 'L’ajout de membres d’équipe, le pointage et le rapport de fin de chantier sont activés par abonnement.',
            ],
            'payment_notice' => 'Vous payez directement les ouvriers que vous affectez, au tarif journalier convenu.',
        ];
    }

    /**
     * « Membre de l’équipe » : affectation, présence, remplacement et actions possibles.
     *
     * @return array<string, mixed>
     */
    public function detail(Assignment $assignment): array
    {
        $assignment->loadMissing(self::WORKER_WITH);
        $days = WorkDay::query()->where('assignment_id', $assignment->getKey())->get(['status']);
        $worked = $days->whereIn('status', [WorkDayStatus::Valide, WorkDayStatus::AValider, WorkDayStatus::EnCours])->count();
        $absent = $days->where('status', WorkDayStatus::Absent)->count();
        $replacedBy = Assignment::query()->with('worker')->where('replaces_assignment_id', $assignment->getKey())->first();
        $replaces = $assignment->replaces_assignment_id ? Assignment::query()->with('worker')->find($assignment->replaces_assignment_id) : null;
        $site = $assignment->mission->site;
        $started = $assignment->planned_start_date->lte(today());
        $live = in_array($assignment->status, self::LIVE, true) && $this->manageable($site);

        return [
            ...$this->member($assignment),
            'kpis' => [
                'presence_rate' => $worked + $absent > 0 ? (int) round($worked * 100 / ($worked + $absent)) : null,
                'worked_days' => $worked,
                'absences' => $absent,
            ],
            'replacement' => $assignment->status === AssignmentStatus::Remplacee ? [
                'reason' => $assignment->end_reason,
                'effective_date' => $replacedBy?->planned_start_date->toDateString(),
                'replaced_by' => $replacedBy ? trim($replacedBy->worker->first_name.' '.$replacedBy->worker->last_name) : null,
            ] : null,
            'replaces' => $replaces ? trim($replaces->worker->first_name.' '.$replaces->worker->last_name) : null,
            'notice' => $assignment->status === AssignmentStatus::EnAttente ? 'L’ouvrier doit accepter l’affectation avant de pouvoir être pointé.' : null,
            'actions' => [
                'replace' => $live && $assignment->status !== AssignmentStatus::EnAttente,
                'cancel' => $live && ! $started,
            ],
            'replacement_reasons' => self::REPLACEMENT_REASONS,
        ];
    }

    /**
     * « Remplacer l’ouvrier » : l’affectation s’arrête la veille de la date d’effet ; le remplaçant reprend le
     * reste de la période, au même rôle, en attente de son accord.
     */
    public function replace(Assignment $assignment, User $author, string $reason, CarbonImmutable $effective, Worker $worker, ?int $dailyRate = null): Assignment
    {
        $site = $assignment->mission->site;
        $this->ensureManageable($site);
        $this->subscriptions->ensureSubscribed($assignment->mission->contractor, 'Remplacement d’un ouvrier');

        if (! in_array($assignment->status, [AssignmentStatus::Confirmee, AssignmentStatus::Active], true)) {
            throw ValidationException::withMessages(['assignment' => $assignment->status === AssignmentStatus::EnAttente
                ? 'L’ouvrier n’a pas encore accepté : annulez plutôt l’affectation.'
                : 'Cette affectation n’est plus active.']);
        }

        if ($effective->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['effective_date' => 'La date d’effet ne peut pas être passée.']);
        }

        if ($effective->lt($assignment->planned_start_date) || $effective->gt($assignment->planned_end_date)) {
            throw ValidationException::withMessages(['effective_date' => 'La date d’effet doit être comprise dans l’affectation ('.$assignment->planned_start_date->format('d/m/Y').' – '.$assignment->planned_end_date->format('d/m/Y').').']);
        }

        if ($worker->is($assignment->worker)) {
            throw ValidationException::withMessages(['worker_id' => 'Choisissez un autre ouvrier.']);
        }

        $end = CarbonImmutable::parse($assignment->planned_end_date);

        if ($conflict = $this->conflict($worker, $effective, $end)) {
            throw ValidationException::withMessages(['worker_id' => trim($worker->first_name.' '.$worker->last_name).' est déjà affecté du '.$conflict->planned_start_date->format('d/m/Y').' au '.$conflict->planned_end_date->format('d/m/Y').'.']);
        }

        return DB::transaction(function () use ($assignment, $reason, $effective, $worker, $dailyRate, $end): Assignment {
            $sameDay = $effective->isSameDay($assignment->planned_start_date);
            $assignment->update([
                'status' => AssignmentStatus::Remplacee,
                'planned_end_date' => $sameDay ? $assignment->planned_end_date : $effective->subDay()->toDateString(),
                'end_reason' => $reason,
            ]);

            $replacement = $assignment->mission->assignments()->create([
                'need_id' => $assignment->need_id,
                'worker_id' => $worker->getKey(),
                'role_label' => $assignment->role_label,
                'planned_start_date' => $effective->toDateString(),
                'planned_end_date' => $end->toDateString(),
                'salary_amount' => $dailyRate ?? (int) $worker->estimated_daily_rate ?: $assignment->salary_amount,
                'salary_period' => SalaryPeriod::Jour,
                'z_rate' => 0,
                'status' => AssignmentStatus::EnAttente,
                'replaces_assignment_id' => $assignment->getKey(),
            ]);

            return $replacement->refresh();
        });
    }

    /**
     * « Annuler l’affectation » : seulement avant son démarrage.
     */
    public function cancel(Assignment $assignment): Assignment
    {
        $this->ensureManageable($assignment->mission->site);

        if (! in_array($assignment->status, self::LIVE, true) || $assignment->planned_start_date->lte(today())) {
            throw ValidationException::withMessages(['assignment' => 'Seule une affectation qui n’a pas démarré peut être annulée : remplacez plutôt l’ouvrier.']);
        }

        DB::transaction(function () use ($assignment): void {
            $assignment->update(['status' => AssignmentStatus::Annulee, 'end_reason' => 'Annulée par l’entrepreneur']);

            if (! $assignment->mission->assignments()->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, self::LIVE))->exists()) {
                $assignment->mission->update(['status' => MissionStatus::Annulee, 'cancellation_reason' => 'Affectation annulée']);
            }
        });

        return $assignment;
    }

    /**
     * Accord ou refus de l’ouvrier sur une affectation directe (Back Office ou démonstration).
     */
    public function decideAgreement(Assignment $assignment, bool $accepted, ?Admin $admin = null): Assignment
    {
        if ($assignment->status !== AssignmentStatus::EnAttente) {
            throw ValidationException::withMessages(['assignment' => 'Cette affectation n’attend pas l’accord de l’ouvrier.']);
        }

        $assignment->loadMissing(['worker', 'mission.site', 'mission.contractor.user']);
        $assignment->update(['status' => $accepted ? AssignmentStatus::Confirmee : AssignmentStatus::Refusee, 'agreement_decided_at' => now()]);

        if (! $accepted && ! $assignment->mission->assignments()->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, self::LIVE))->exists()) {
            $assignment->mission->update(['status' => MissionStatus::Annulee, 'cancellation_reason' => 'Refus de l’ouvrier']);
        }

        $name = trim($assignment->worker->first_name.' '.$assignment->worker->last_name);
        $this->notifier->notify($assignment->mission->contractor, $accepted ? 'team.accepted' : 'team.refused',
            $accepted ? 'Affectation acceptée' : 'Affectation refusée',
            $name.($accepted ? ' a accepté' : ' a refusé').' son affectation sur « '.$assignment->mission->site->label.' » ('.$assignment->planned_start_date->format('d/m/Y').' – '.$assignment->planned_end_date->format('d/m/Y').').',
            ['type' => 'team_member', 'id' => $assignment->id, 'site_id' => $assignment->mission->site_id], $assignment);

        return $assignment;
    }

    /**
     * Affectation de l’équipe de l’entrepreneur sur ce chantier.
     */
    public function findAssignment(Contractor $contractor, Site $site, string $id): Assignment
    {
        $assignment = str($id)->isUuid()
            ? Assignment::query()->with(self::WORKER_WITH)->whereKey($id)->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey())->where('contractor_id', $contractor->getKey()))->first()
            : null;

        abort_unless($assignment, 404, 'Membre de l’équipe introuvable.');

        return $assignment;
    }

    /**
     * Période où l’entrepreneur peut affecter des ouvriers : celle de son forfait.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(Contractor $contractor, Site $site): array
    {
        $card = $this->sites->card($contractor, $site);

        return [CarbonImmutable::parse($card['start_date']), CarbonImmutable::parse($card['end_date'])];
    }

    /**
     * Le chantier est-il confié à un entrepreneur (l’équipe est alors gérée par lui) ?
     */
    public static function isContractorSite(Site $site): bool
    {
        return $site->offers()->whereNotNull('contractor_bid_id')->exists();
    }

    /**
     * Abonnement actif et place disponible : ouvriers actifs sur le chantier, plus les nouveaux, dans la limite de la
     * formule (5 ou 20).
     */
    public function ensureCapacity(Contractor $contractor, Site $site, int $adding): void
    {
        $this->subscriptions->ensureSubscribed($contractor, 'Ajout d’ouvriers');
        $seats = $this->subscriptions->seats($contractor);
        $active = Assignment::query()
            ->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey())->where('contractor_id', $contractor->getKey()))
            ->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, self::LIVE))
            ->count();

        if ($active + $adding > $seats) {
            throw ValidationException::withMessages(['workers' => 'Plafond de votre abonnement atteint ('.$seats.' membres actifs par chantier).'.($seats < 20 ? ' Passez à Pro pour en affecter jusqu’à 20.' : '')]);
        }
    }

    private function manageable(Site $site): bool
    {
        return in_array($site->status->value, ['confirme', 'en_cours'], true);
    }

    private function ensureManageable(Site $site): void
    {
        if (! $this->manageable($site)) {
            throw ValidationException::withMessages(['site' => 'L’équipe ne peut plus être modifiée : le chantier est '.mb_strtolower($site->status->label()).'.']);
        }
    }

    /**
     * Affectation en cours ou à venir de l’ouvrier qui chevauche la période, sur n’importe quel chantier.
     */
    public function conflict(Worker $worker, CarbonImmutable $start, CarbonImmutable $end): ?Assignment
    {
        return Assignment::query()->with('mission.site')
            ->where('worker_id', $worker->getKey())
            ->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, self::LIVE))
            ->where('planned_start_date', '<=', $end->toDateString())
            ->where('planned_end_date', '>=', $start->toDateString())
            ->first();
    }

    /**
     * @return Collection<int, Assignment>
     */
    private function liveAssignments(Site $site): Collection
    {
        return Assignment::query()
            ->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey()))
            ->whereIn('status', array_map(fn (AssignmentStatus $status) => $status->value, self::LIVE))
            ->get(['id', 'worker_id']);
    }

    /**
     * Besoin, instantané (sans montant pour le promoteur) et mission « équipe de l’entrepreneur », puis l’affectation
     * (en attente de l’accord de l’ouvrier pour une affectation directe, confirmée pour une recherche d’effectifs).
     */
    public function createAssignment(Contractor $contractor, Site $site, User $author, Worker $worker, string $role, int $rate, CarbonImmutable $start, CarbonImmutable $end, AssignmentStatus $status = AssignmentStatus::EnAttente, ?string $description = null): Assignment
    {
        $offer = $site->offers()->whereNotNull('contractor_bid_id')->latest()->firstOrFail();
        $days = (int) $start->diffInDays($end) + 1;
        $trade = $worker->workerTrades->firstWhere('is_primary', true) ?? $worker->workerTrades->first();

        $need = $offer->needs()->create([
            'reference' => sprintf('BS-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('need_reference_seq')")),
            'site_id' => $site->getKey(),
            'offer_type' => OfferType::Contractuelle,
            'trade_id' => $trade->trade_id,
            'role_label' => $role,
            'headcount' => 1,
            'title' => 'Équipe de '.$contractor->name.' · '.$role,
            'description' => $description ?? trim($worker->first_name.' '.$worker->last_name).' affecté directement par '.$contractor->name.' (payé hors plateforme).',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'duration_days' => $days,
            'payment_frequency' => PaymentFrequency::Hebdomadaire,
            'salary_amount' => $rate,
            'salary_period' => SalaryPeriod::Jour,
            'status' => NeedStatus::Confirme,
            'submitted_at' => now(),
            'created_by_user_id' => $author->getKey(),
        ]);

        $snapshot = $need->pricingSnapshots()->create([
            'kind' => PricingSnapshotKind::Confirme,
            'offer_type' => OfferType::Contractuelle,
            'price_origin' => PriceOrigin::Promoteur,
            'x_rate' => 0,
            'y_rate' => 0,
            'z_rate' => 0,
            'r_rate' => 0,
            'parameter_ids' => [],
            'headcount' => 1,
            'planned_days' => $days,
            'workers_base_amount' => 0,
            'promoter_commission_amount' => 0,
            'worker_commission_amount' => 0,
            'total_promoter_amount' => 0,
            'deposit_amount' => 0,
            'balance_amount' => 0,
            'lines' => [['worker_id' => $worker->id, 'daily_rate' => $rate, 'days' => $days, 'paid_by' => 'entrepreneur']],
            'computed_by_type' => $author->getMorphClass(),
            'computed_by_id' => $author->getKey(),
        ]);

        $mission = $need->missions()->create([
            'reference' => sprintf('MI-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('mission_reference_seq')")),
            'site_id' => $site->getKey(),
            'pricing_snapshot_id' => $snapshot->getKey(),
            'contractor_id' => $contractor->getKey(),
            'status' => MissionStatus::Confirmee,
            'planned_start_date' => $start->toDateString(),
            'planned_end_date' => $end->toDateString(),
            'planned_days' => $days,
            'deposit_paid_at' => now(),
            'confirmed_at' => now(),
        ]);

        return $mission->assignments()->create([
            'need_id' => $need->getKey(),
            'worker_id' => $worker->getKey(),
            'role_label' => $role,
            'planned_start_date' => $start->toDateString(),
            'planned_end_date' => $end->toDateString(),
            'salary_amount' => $rate,
            'salary_period' => SalaryPeriod::Jour,
            'z_rate' => 0,
            'status' => $status,
            'agreement_decided_at' => $status === AssignmentStatus::EnAttente ? null : now(),
        ])->load(self::WORKER_WITH);
    }

    private function days(Assignment $assignment): int
    {
        return (int) $assignment->planned_start_date->diffInDays($assignment->planned_end_date) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function member(Assignment $assignment): array
    {
        $worker = $assignment->worker;
        $today = today();
        $days = $this->days($assignment);

        return [
            'id' => $assignment->id,
            'worker' => [
                'id' => $worker->id,
                'name' => trim($worker->first_name.' '.$worker->last_name),
                'initials' => mb_strtoupper(mb_substr((string) $worker->first_name, 0, 1).mb_substr((string) $worker->last_name, 0, 1)),
                'photo_url' => $worker->photoMedia ? $this->media->temporaryUrl($worker->photoMedia) : null,
                'trade' => ($worker->workerTrades->firstWhere('is_primary', true) ?? $worker->workerTrades->first())?->trade?->only(['id', 'code', 'label']),
                'rating' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null,
            ],
            'role' => $assignment->role_label,
            'start_date' => $assignment->planned_start_date->toDateString(),
            'end_date' => $assignment->planned_end_date->toDateString(),
            'days' => $days,
            'daily_rate' => (int) $assignment->salary_amount,
            'planned_cost' => $days * (int) $assignment->salary_amount,
            'status' => ['value' => $assignment->status->value, 'label' => $assignment->status->label()],
            'display_status' => match (true) {
                $assignment->status === AssignmentStatus::EnAttente => ['value' => 'en_attente', 'label' => 'En attente d’accord'],
                in_array($assignment->status, [AssignmentStatus::Confirmee, AssignmentStatus::Active], true) && $assignment->planned_start_date->gt($today) => ['value' => 'confirmee', 'label' => 'Confirmée · arrive le '.$assignment->planned_start_date->format('d/m/Y')],
                in_array($assignment->status, [AssignmentStatus::Confirmee, AssignmentStatus::Active], true) && $assignment->planned_end_date->lt($today) => ['value' => 'terminee', 'label' => 'Terminée'],
                in_array($assignment->status, [AssignmentStatus::Confirmee, AssignmentStatus::Active], true) => ['value' => 'active', 'label' => 'Sur le chantier depuis le '.$assignment->planned_start_date->format('d/m/Y')],
                default => ['value' => $assignment->status->value, 'label' => $assignment->status->label()],
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function worker(Worker $worker, bool $inTeam): array
    {
        /** @var WorkerTrade|null $primary */
        $primary = $worker->workerTrades->firstWhere('is_primary', true) ?? $worker->workerTrades->first();
        $available = $worker->availability_status === AvailabilityStatus::Disponible;

        return [
            'id' => $worker->id,
            'name' => trim($worker->first_name.' '.$worker->last_name),
            'initials' => mb_strtoupper(mb_substr((string) $worker->first_name, 0, 1).mb_substr((string) $worker->last_name, 0, 1)),
            'photo_url' => $worker->photoMedia ? $this->media->temporaryUrl($worker->photoMedia) : null,
            'trade' => $primary?->trade?->only(['id', 'code', 'label']),
            'other_trades' => $worker->workerTrades->reject(fn (WorkerTrade $trade) => $trade->is($primary))->map(fn (WorkerTrade $trade) => $trade->trade->only(['id', 'code', 'label']))->values()->all(),
            'experience_years' => $primary?->years_experience,
            'commune' => $worker->residenceCommune?->only(['id', 'name']),
            'daily_rate' => $worker->estimated_daily_rate !== null ? (int) $worker->estimated_daily_rate : null,
            'rating' => ['average' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null, 'count' => (int) $worker->rating_count],
            'missions_completed' => (int) $worker->missions_completed,
            'available' => $available,
            'in_team' => $inTeam,
            'selectable' => $available && ! $inTeam,
            'tag' => $inTeam ? 'Dans l’équipe' : ($available ? 'Disponible' : 'Indisponible'),
        ];
    }
}
