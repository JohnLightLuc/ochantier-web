<?php

namespace App\Support\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\RatingStatus;
use App\Enums\WorkDayStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Rating;
use App\Models\Replacement;
use App\Models\Site;
use App\Models\UnavailabilityRequest;
use App\Models\User;
use App\Models\WorkDay;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Models\WorkerPayout;
use App\Models\WorkerStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Onglets en lecture seule de la fiche ouvrier du Back Office : disponibilités, affectations, pointages, gains,
 * avis, indisponibilités et remplacements, historique.
 */
class WorkerRelations
{
    /**
     * Reversements comptés comme versés à l’ouvrier.
     */
    private const PAID = [WorkerPayoutStatus::Envoye, WorkerPayoutStatus::Confirme];

    private const ADMIN_LABELS = [
        'worker.created' => 'a enregistré l’ouvrier',
        'worker.updated' => 'a modifié la fiche',
        'worker.approved' => 'a validé le dossier',
        'worker.corrections_requested' => 'a demandé des corrections',
        'worker.rejected' => 'a refusé le dossier',
        'worker.suspended' => 'a suspendu l’ouvrier',
        'worker.reactivated' => 'a réactivé l’ouvrier',
        'worker.excluded' => 'a exclu l’ouvrier',
        'document.uploaded' => 'a ajouté une pièce',
        'document.approved' => 'a validé une pièce',
        'document.rejected' => 'a rejeté une pièce',
    ];

    /**
     * Disponibilité actuelle, mission en cours, missions à venir et disponibilités déclarées.
     *
     * @return array<string, mixed>
     */
    public function availability(Worker $worker): array
    {
        $planned = $worker->assignments()
            ->whereIn('status', [AssignmentStatus::Confirmee, AssignmentStatus::Active])
            ->with(['mission.site.promoterProfile.user.ownedOrganizations', 'need.trade'])
            ->orderBy('planned_start_date')
            ->get();

        return [
            'status' => $this->status($worker->availability_status),
            'available_from' => $worker->available_from?->toDateString(),
            'current' => ($current = $planned->firstWhere('status', AssignmentStatus::Active)) ? $this->assignment($current) : null,
            'upcoming' => $planned->where('status', AssignmentStatus::Confirmee)->map(fn (Assignment $assignment) => $this->assignment($assignment))->values()->all(),
            'open_unavailabilities' => $worker->unavailabilityRequests()->whereNotIn('status', ['cloturee', 'annulee', 'refusee'])->count(),
            'declarations' => WorkerAvailability::query()->where('worker_id', $worker->getKey())->latest('effective_from')->limit(20)->get()
                ->map(fn (WorkerAvailability $a) => [
                    'id' => $a->id,
                    'status' => $this->status($a->status),
                    'from' => $a->effective_from?->toIso8601String(),
                    'to' => $a->effective_to?->toIso8601String(),
                    'next_available_date' => $a->next_available_date?->toDateString(),
                    'source' => $this->status($a->source),
                    'comment' => $a->comment,
                ])->all(),
        ];
    }

    /**
     * Affectations de l’ouvrier, les plus récentes d’abord.
     *
     * @return LengthAwarePaginator<int, Assignment>
     */
    public function assignments(Worker $worker, ?string $status, int $perPage = 20): LengthAwarePaginator
    {
        return $this->assignmentQuery($worker)
            ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest('planned_start_date')
            ->latest('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array<string, int>
     */
    public function assignmentCounts(Worker $worker): array
    {
        $counts = $worker->assignments()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['all' => (int) $counts->sum(), ...collect(AssignmentStatus::cases())->mapWithKeys(fn (AssignmentStatus $s) => [$s->value => (int) $counts->get($s->value, 0)])->all()];
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(Assignment $assignment): array
    {
        $mission = $assignment->mission;

        return [
            'id' => $assignment->id,
            'mission' => $mission ? ['id' => $mission->id, 'reference' => $mission->reference, 'status' => $this->status($mission->status)] : null,
            'site' => $this->site($mission?->site),
            'promoter' => $this->promoter($mission?->site),
            'trade' => $assignment->need?->trade?->label ?? $assignment->role_label,
            'role' => $assignment->role_label,
            'is_team_lead' => (bool) $assignment->is_team_lead,
            'start_date' => $assignment->planned_start_date?->toDateString(),
            'end_date' => $assignment->planned_end_date?->toDateString(),
            'actual_start_at' => $assignment->actual_start_at?->toIso8601String(),
            'actual_end_at' => $assignment->actual_end_at?->toIso8601String(),
            'promoter_daily_rate' => $assignment->promoter_daily_rate,
            'worker_net_daily_rate' => $assignment->worker_net_daily_rate,
            'worked_days' => (int) ($assignment->worked_days_count ?? 0),
            'validated_days' => (int) ($assignment->validated_days_count ?? 0),
            'replaces' => $assignment->replaces_assignment_id !== null,
            'end_reason' => $assignment->end_reason,
            'status' => $this->status($assignment->status),
        ];
    }

    /**
     * Journées pointées, les plus récentes d’abord, avec l’heure d’arrivée et de départ.
     *
     * @return LengthAwarePaginator<int, WorkDay>
     */
    public function attendance(Worker $worker, ?string $status, int $perPage = 30): LengthAwarePaginator
    {
        return WorkDay::query()
            ->where('worker_id', $worker->getKey())
            ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->with(['mission.site', 'arrivalEvent', 'departureEvent', 'validatedByUser'])
            ->latest('work_date')
            ->paginate($perPage);
    }

    /**
     * Journées par statut et taux de présence (journées validées sur journées validées ou absences).
     *
     * @return array<string, mixed>
     */
    public function attendanceSummary(Worker $worker): array
    {
        $rows = WorkDay::query()->where('worker_id', $worker->getKey())->toBase()->selectRaw('status, count(*) as total, coalesce(sum(worked_minutes), 0) as minutes')->groupBy('status')->get()->keyBy('status');
        $counts = $rows->map(fn (object $row) => (int) $row->total);
        $validated = (int) $counts->get(WorkDayStatus::Valide->value, 0);
        $absent = (int) $counts->get(WorkDayStatus::Absent->value, 0);

        return [
            'counts' => ['all' => (int) $counts->sum(), ...collect(WorkDayStatus::cases())->mapWithKeys(fn (WorkDayStatus $s) => [$s->value => (int) $counts->get($s->value, 0)])->all()],
            'presence_rate' => $validated + $absent > 0 ? round($validated / ($validated + $absent) * 100, 1) : null,
            'worked_minutes' => (int) ($rows->get(WorkDayStatus::Valide->value)->minutes ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workDay(WorkDay $day): array
    {
        $time = fn ($event) => $event?->recorded_at ? CarbonImmutable::parse($event->recorded_at)->setTimezone(config('app.timezone'))->format('H:i') : null;

        return [
            'id' => $day->id,
            'date' => $day->work_date?->toDateString(),
            'site' => $this->site($day->mission?->site),
            'arrival' => $time($day->arrivalEvent),
            'departure' => $time($day->departureEvent),
            'arrival_out_of_zone' => (bool) $day->arrivalEvent?->out_of_zone,
            'worked_minutes' => $day->worked_minutes,
            'observation' => $day->observation,
            'validated_by' => $day->validatedByUser ? trim($day->validatedByUser->first_name.' '.$day->validatedByUser->last_name) : null,
            'validated_at' => $day->validated_at?->toIso8601String(),
            'status' => $this->status($day->status),
        ];
    }

    /**
     * Gains acquis par mission (journées validées × tarif net journalier), reversements et totaux.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function earnings(Worker $worker): array
    {
        $payouts = WorkerPayout::query()->where('worker_id', $worker->getKey())->with('mission.site')->latest('created_at')->get();
        $paidByMission = $payouts->whereIn('status', self::PAID)->groupBy('mission_id')->map(fn (Collection $group) => (int) $group->sum('net_amount'));

        $rows = $this->assignmentQuery($worker)
            ->where('status', '!=', AssignmentStatus::Annulee)
            ->latest('planned_start_date')
            ->get()
            ->map(function (Assignment $assignment) use ($paidByMission): array {
                $days = (int) $assignment->validated_days_count;
                $earned = $days * (int) $assignment->worker_net_daily_rate;
                $paid = (int) $paidByMission->get($assignment->mission_id, 0);

                return [
                    ...array_intersect_key($this->assignment($assignment), array_flip(['id', 'mission', 'site', 'start_date', 'end_date', 'worker_net_daily_rate', 'validated_days', 'status'])),
                    'earned' => $earned,
                    'paid' => $paid,
                    'remaining' => max(0, $earned - $paid),
                ];
            })
            ->filter(fn (array $row) => $row['validated_days'] > 0 || $row['paid'] > 0)
            ->values();

        return [
            'data' => $rows->all(),
            'meta' => [
                'totals' => ['earned' => (int) $rows->sum('earned'), 'paid' => (int) $payouts->whereIn('status', self::PAID)->sum('net_amount'), 'remaining' => (int) $rows->sum('remaining')],
                'payouts' => $payouts->map(fn (WorkerPayout $payout) => [
                    'id' => $payout->id,
                    'reference' => $payout->reference,
                    'site' => $this->site($payout->mission?->site),
                    'period_start' => $payout->period_start?->toDateString(),
                    'period_end' => $payout->period_end?->toDateString(),
                    'gross_amount' => $payout->gross_amount,
                    'commission_amount' => $payout->commission_amount,
                    'net_amount' => $payout->net_amount,
                    'status' => $this->status($payout->status),
                    'created_at' => $payout->created_at?->toIso8601String(),
                ])->all(),
            ],
        ];
    }

    /**
     * Avis individuels reçus (publiés ou masqués), moyenne et répartition des avis publiés.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function ratings(Worker $worker): array
    {
        $ratings = Rating::query()
            ->where('worker_id', $worker->getKey())
            ->whereIn('status', [RatingStatus::Publie, RatingStatus::Masque])
            ->with(['site', 'authorUser'])
            ->latest('published_at')
            ->get();
        $published = $ratings->where('status', RatingStatus::Publie);
        $count = $published->count();

        return [
            'data' => $ratings->map(fn (Rating $rating) => [
                'id' => $rating->id,
                'score' => $rating->overall_score,
                'satisfaction_tag' => $rating->satisfaction_tag,
                'comment' => $rating->comment,
                'recommends' => $rating->recommends,
                'site' => $this->site($rating->site),
                'author' => $rating->authorUser ? trim($rating->authorUser->first_name.' '.$rating->authorUser->last_name) : null,
                'published_at' => $rating->published_at?->toIso8601String(),
                'status' => $this->status($rating->status),
                'moderation' => $rating->moderation_status ? ['value' => $rating->moderation_status->value, 'label' => $rating->moderation_status->label(), 'reason' => $rating->moderation_reason] : null,
            ])->values()->all(),
            'meta' => [
                'average' => $count ? round($published->avg('overall_score'), 1) : null,
                'count' => $count,
                'hidden_count' => $ratings->where('status', RatingStatus::Masque)->count(),
                'distribution' => collect([5, 4, 3, 2, 1])->map(fn (int $score) => ['score' => $score, 'count' => $c = $published->where('overall_score', $score)->count(), 'percent' => $count ? (int) round($c / $count * 100) : 0])->all(),
            ],
        ];
    }

    /**
     * Indisponibilités déclarées par ou pour l’ouvrier (avec le remplacement lancé), et missions où il a remplacé
     * un autre ouvrier.
     *
     * @return array<string, mixed>
     */
    public function replacements(Worker $worker): array
    {
        $requests = UnavailabilityRequest::query()
            ->where('worker_id', $worker->getKey())
            ->with(['mission.site', 'reason', 'replacements.replacementAssignment.worker'])
            ->latest('effective_at')
            ->get();

        $replaced = Replacement::query()
            ->whereHas('replacementAssignment', fn (Builder $query) => $query->where('worker_id', $worker->getKey()))
            ->with(['mission.site', 'originalAssignment.worker'])
            ->latest('created_at')
            ->get();

        return [
            'unavailabilities' => $requests->map(function (UnavailabilityRequest $request): array {
                $replacement = $request->replacements->sortByDesc('created_at')->first();

                return [
                    'id' => $request->id,
                    'site' => $this->site($request->mission?->site),
                    'phase' => $this->status($request->phase),
                    'effective_at' => $request->effective_at?->toIso8601String(),
                    'estimated_duration_days' => $request->estimated_duration_days,
                    'reason' => $request->reason?->label ?? $request->reason_comment,
                    'comment' => $request->reason_comment,
                    'is_urgent' => (bool) $request->is_urgent,
                    'status' => $this->status($request->status),
                    'replacement' => $replacement ? [
                        'status' => $this->status($replacement->status),
                        'worker' => $this->workerName($replacement->replacementAssignment?->worker),
                    ] : null,
                ];
            })->values()->all(),
            'replaced_others' => $replaced->map(fn (Replacement $replacement) => [
                'id' => $replacement->id,
                'site' => $this->site($replacement->mission?->site),
                'replaced_worker' => $this->workerName($replacement->originalAssignment?->worker),
                'handover_at' => $replacement->handover_at?->toIso8601String(),
                'status' => $this->status($replacement->status),
            ])->values()->all(),
        ];
    }

    /**
     * Historique : changements de statut du dossier, actions des administrateurs sur l’ouvrier et ses pièces, et
     * événements des missions (affectation, fin, remplacement). $origin : admin, commercial, systeme, mission ou null.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function history(Worker $worker, ?string $origin, int $page = 1, int $perPage = 30): LengthAwarePaginator
    {
        $admin = (new Admin)->getMorphClass();
        $documents = Document::query()->where('owner_type', $worker->getMorphClass())->where('owner_id', $worker->getKey())->pluck('id');
        $events = collect();

        if (! $origin || in_array($origin, ['commercial', 'systeme'], true)) {
            $histories = WorkerStatusHistory::query()->where('worker_id', $worker->getKey())->where('actor_type', '!=', $admin)->get();
            $commercials = User::query()
                ->whereIn('id', $histories->where('actor_type', (new User)->getMorphClass())->pluck('actor_id')->filter()->unique())
                ->get(['id', 'first_name', 'last_name'])
                ->keyBy('id');
            $events = $events->concat($histories->map(fn (WorkerStatusHistory $history) => $this->statusEvent($history, $commercials)));
        }

        if (! $origin || $origin === 'admin') {
            $events = $events->concat(AuditLog::query()
                ->where('actor_type', $admin)
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $query->where('subject_type', $worker->getMorphClass())->where('subject_id', $worker->getKey()))
                    ->orWhere(fn (Builder $query) => $query->where('subject_type', (new Document)->getMorphClass())->whereIn('subject_id', $documents)))
                ->with(['actor', 'subject' => fn (MorphTo $subject) => $subject->morphWith([Document::class => ['documentType']])])
                ->get()
                ->map(fn (AuditLog $log) => $this->auditEvent($log)));
        }

        if (! $origin || $origin === 'mission') {
            $events = $events->concat($this->missionEvents($worker));
        }

        $events = $events->filter(fn (array $event) => ! $origin || $event['origin']['value'] === $origin)->sortByDesc('at')->values();

        return new Paginator($events->forPage($page, $perPage)->values(), $events->count(), $perPage, $page);
    }

    /**
     * @return Builder<Assignment>
     */
    private function assignmentQuery(Worker $worker): Builder
    {
        return Assignment::query()
            ->where('worker_id', $worker->getKey())
            ->with(['mission.site.promoterProfile.user.ownedOrganizations', 'need.trade'])
            ->withCount([
                'workDays as validated_days_count' => fn (Builder $query) => $query->where('status', WorkDayStatus::Valide),
                'workDays as worked_days_count' => fn (Builder $query) => $query->whereIn('status', [WorkDayStatus::Valide, WorkDayStatus::AValider, WorkDayStatus::EnCours]),
            ]);
    }

    /**
     * @param  Collection<string, User>  $commercials  Commerciaux auteurs des changements, par identifiant.
     * @return array<string, mixed>
     */
    private function statusEvent(WorkerStatusHistory $history, Collection $commercials): array
    {
        $commercial = $history->actor_type === (new User)->getMorphClass();
        $author = $commercial ? $commercials->get($history->actor_id) : null;
        $labels = ['soumis' => 'a transmis le dossier', 'en_controle' => 'a pris le dossier en charge', 'valide' => 'a validé le dossier', 'a_corriger' => 'a demandé des corrections', 'refuse' => 'a refusé le dossier', 'suspendu' => 'a suspendu l’ouvrier', 'exclu' => 'a exclu l’ouvrier', 'brouillon' => 'a créé le dossier'];
        $from = $history->from_status?->value;
        $to = $history->to_status?->value;

        return $this->event(
            'status:'.$history->id,
            $history->created_at,
            $commercial ? ['value' => 'commercial', 'label' => 'Commercial'] : ['value' => 'systeme', 'label' => 'Système'],
            $commercial ? (trim($author?->first_name.' '.$author?->last_name) ?: 'Le commercial') : 'O’CHANTIER',
            $commercial ? ($labels[$to] ?? 'a changé le statut') : ($to === 'valide' && $from === 'suspendu' ? 'a levé la suspension (date de fin atteinte)' : 'a changé le statut du dossier'),
            $history->reason,
            $from,
            $to,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditEvent(AuditLog $log): array
    {
        $values = $log->after_values ?? [];
        $detail = $values['reason'] ?? null;

        if ($log->action === 'worker.updated') {
            $detail = count($values).' information(s) modifiée(s)';
        } elseif (str_starts_with($log->action, 'document.')) {
            $detail = $log->subject instanceof Document ? $log->subject->documentType?->label : ($values['document_type'] ?? null);
        } elseif ($log->action === 'worker.corrections_requested') {
            $detail = count($values['corrections'] ?? []).' correction(s)';
        }

        return $this->event(
            'audit:'.$log->id,
            $log->created_at,
            ['value' => 'admin', 'label' => 'Back Office'],
            $log->actor instanceof Admin ? $log->actor->fullName() : 'Administrateur supprimé',
            self::ADMIN_LABELS[$log->action] ?? $log->action,
            is_string($detail) ? $detail : null,
            $log->before_values['verification_status'] ?? null,
            $values['verification_status'] ?? null,
        );
    }

    /**
     * Affectations (début, fin) et remplacements, datés.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function missionEvents(Worker $worker): Collection
    {
        $mission = ['value' => 'mission', 'label' => 'Mission'];

        return Assignment::query()->where('worker_id', $worker->getKey())->with('mission.site')->get()->flatMap(function (Assignment $assignment) use ($mission): array {
            $site = $assignment->mission?->site?->label ?? 'un chantier';
            $events = [$this->event('assignment:'.$assignment->id, $assignment->created_at, $mission, 'O’CHANTIER', $assignment->replaces_assignment_id ? 'a affecté l’ouvrier en remplacement' : 'a affecté l’ouvrier', $site.($assignment->planned_start_date ? ' · début le '.$assignment->planned_start_date->format('d/m/Y') : ''))];

            if ($assignment->actual_end_at || in_array($assignment->status, [AssignmentStatus::Terminee, AssignmentStatus::Remplacee, AssignmentStatus::Annulee], true)) {
                $events[] = $this->event('assignment-end:'.$assignment->id, $assignment->actual_end_at ?? $assignment->updated_at, $mission, 'O’CHANTIER', match ($assignment->status) {
                    AssignmentStatus::Remplacee => 'a été remplacé sur la mission',
                    AssignmentStatus::Annulee => 'a vu son affectation annulée',
                    default => 'a terminé la mission',
                }, $site.($assignment->end_reason ? ' · '.$assignment->end_reason : ''));
            }

            return $events;
        });
    }

    /**
     * @param  array{value: string, label: string}  $origin
     * @return array<string, mixed>
     */
    private function event(string $id, mixed $at, array $origin, string $author, string $what, ?string $detail = null, ?string $from = null, ?string $to = null): array
    {
        return ['id' => $id, 'at' => CarbonImmutable::parse($at)->toIso8601String(), 'origin' => $origin, 'author' => $author, 'what' => $what, 'detail' => $detail, 'from' => $from, 'to' => $to];
    }

    /**
     * @return array{id: string, title: string}|null
     */
    private function site(?Site $site): ?array
    {
        return $site ? ['id' => $site->id, 'title' => $site->label] : null;
    }

    private function promoter(?Site $site): ?string
    {
        $user = $site?->promoterProfile?->user;

        return $user?->ownedOrganizations?->first()?->legal_name ?? ($user ? trim($user->first_name.' '.$user->last_name) : null);
    }

    private function workerName(?Worker $worker): ?string
    {
        return $worker ? trim($worker->first_name.' '.$worker->last_name) : null;
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function status(?\BackedEnum $status): ?array
    {
        return $status ? ['value' => (string) $status->value, 'label' => method_exists($status, 'label') ? $status->label() : (string) $status->value] : null;
    }
}
