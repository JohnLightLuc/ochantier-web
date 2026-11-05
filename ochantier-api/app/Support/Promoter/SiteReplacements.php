<?php

namespace App\Support\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\ReplacementPromoterDecision;
use App\Enums\ReplacementStatus;
use App\Enums\SuggestionStatus;
use App\Enums\UnavailabilityDeclarant;
use App\Enums\UnavailabilityRequestPhase;
use App\Enums\UnavailabilityRequestStatus;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\ReasonCode;
use App\Models\Replacement;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WorkerTrade;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Remplacer un ouvrier » : le promoteur signale l’ouvrier à remplacer avec un motif, le Back office (ou la
 * simulation locale) propose des remplaçants disponibles au même tarif promoteur, le promoteur en choisit un.
 *
 * Le remplaçant rejoint le chantier le lendemain ; le pointage et la rémunération de l’ouvrier remplacé s’arrêtent
 * le soir même. Le promoteur garde le tarif journalier figé de l’affectation d’origine.
 */
class SiteReplacements
{
    public const REASON_CATEGORY = 'remplacement';

    /**
     * Motifs proposés au promoteur (« Motif »), dans l’ordre de l’écran.
     */
    public const PROMOTER_REASONS = ['absences_repetees', 'qualite', 'indisponibilite', 'autre'];

    /**
     * Remplacement en cours : signalé, en recherche ou remplaçants proposés.
     */
    public const OPEN = [ReplacementStatus::Signale, ReplacementStatus::Recherche, ReplacementStatus::Propose];

    public function __construct(private MediaStore $media, private SiteAttendance $attendance) {}

    /**
     * @return Collection<int, array{code: string, label: string}>
     */
    public function reasons(): Collection
    {
        return ReasonCode::query()->where('category', self::REASON_CATEGORY)->whereIn('code', self::PROMOTER_REASONS)->where('is_active', true)->get()
            ->sortBy(fn (ReasonCode $reason) => array_search($reason->code, self::PROMOTER_REASONS, true))
            ->map(fn (ReasonCode $reason) => ['code' => $reason->code, 'label' => $reason->label])
            ->values();
    }

    public function open(Assignment $assignment): ?Replacement
    {
        return Replacement::query()->where('original_assignment_id', $assignment->getKey())->whereIn('status', self::OPEN)->latest()->first();
    }

    /**
     * Signale l’ouvrier à remplacer : demande d’indisponibilité (déclarée par le promoteur ou le Back Office) et recherche d’un
     * remplaçant du même métier, au plus au tarif promoteur de l’affectation.
     */
    public function request(Assignment $assignment, User|Admin $author, ReasonCode $reason, ?string $comment): Replacement
    {
        if (! in_array($assignment->status, [AssignmentStatus::Confirmee, AssignmentStatus::Active], true) || ! in_array($assignment->mission->status, SiteAttendance::OPEN_MISSIONS, true)) {
            throw ValidationException::withMessages(['assignment' => $this->attendance->name($assignment).' ne fait plus partie de l’équipe de ce chantier.']);
        }

        if ($this->open($assignment)) {
            throw ValidationException::withMessages(['assignment' => 'Un remplacement de '.$this->attendance->name($assignment).' est déjà en cours.']);
        }

        if (CarbonImmutable::tomorrow()->greaterThan($assignment->planned_end_date)) {
            throw ValidationException::withMessages(['assignment' => 'La mission de '.$this->attendance->name($assignment).' se termine aujourd’hui : aucun remplacement possible.']);
        }

        return DB::transaction(function () use ($assignment, $author, $reason, $comment): Replacement {
            $started = $assignment->status === AssignmentStatus::Active;
            $unavailability = $assignment->unavailabilityRequests()->create([
                'mission_id' => $assignment->mission_id,
                'worker_id' => $assignment->worker_id,
                'phase' => $started ? UnavailabilityRequestPhase::EnExecution : UnavailabilityRequestPhase::AvantDemarrage,
                'declared_by_type' => $author instanceof Admin ? UnavailabilityDeclarant::Admin : UnavailabilityDeclarant::Promoteur,
                'declared_by_id' => $author->getKey(),
                'effective_at' => now(),
                'last_worked_date' => $started ? $assignment->workDays()->where('status', '!=', WorkDayStatus::Absent)->max('work_date') : null,
                'reason_id' => $reason->getKey(),
                'reason_comment' => $comment,
                'status' => UnavailabilityRequestStatus::RemplacementRecherche,
            ]);

            $replacement = Replacement::query()->create([
                'unavailability_request_id' => $unavailability->getKey(),
                'mission_id' => $assignment->mission_id,
                'original_assignment_id' => $assignment->getKey(),
                'requested_by_user_id' => $author instanceof User ? $author->getKey() : null,
                'requested_criteria' => [
                    'trade_id' => $assignment->need->trade_id,
                    'max_promoter_daily_rate' => $assignment->promoter_daily_rate,
                    'from_date' => $this->handoverDate($assignment)->toDateString(),
                    'to_date' => $assignment->planned_end_date->toDateString(),
                ],
                'promoter_decision' => ReplacementPromoterDecision::EnAttente,
                'status' => ReplacementStatus::Recherche,
            ]);
            $this->history($replacement, null, ReplacementStatus::Recherche, 'Remplacement demandé : '.$reason->label, $author);

            return $replacement;
        });
    }

    /**
     * Remplaçants proposés au promoteur pour ce remplacement.
     *
     * @return Collection<int, Suggestion>
     */
    public function candidates(Replacement $replacement): Collection
    {
        return Suggestion::query()
            ->whereHas('matchingRun', fn ($query) => $query->where('replacement_id', $replacement->getKey()))
            ->where('status', SuggestionStatus::ProposePromoteur)
            ->with(['worker.photoMedia', 'worker.workerTrades.trade'])
            ->orderBy('rank')
            ->get();
    }

    /**
     * « Confirmer le remplacement » : le remplaçant est affecté à partir du lendemain, aux conditions promoteur de
     * l’affectation d’origine ; l’ouvrier remplacé est arrêté le soir même.
     */
    public function accept(Replacement $replacement, User $author, string $suggestionId): Replacement
    {
        if ($replacement->status !== ReplacementStatus::Propose) {
            throw ValidationException::withMessages(['suggestion_id' => 'Aucun remplaçant n’est proposé pour le moment.']);
        }

        $candidates = $this->candidates($replacement);
        $chosen = $candidates->firstWhere('id', $suggestionId)
            ?? throw ValidationException::withMessages(['suggestion_id' => 'Choisissez un remplaçant parmi ceux proposés.']);

        return DB::transaction(function () use ($replacement, $author, $candidates, $chosen): Replacement {
            $original = $replacement->originalAssignment()->with(['mission', 'need'])->firstOrFail();
            $handover = $this->handoverDate($original);
            $actor = ['actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()];
            $reason = $replacement->unavailabilityRequest->reason?->label;

            $new = Assignment::query()->create([
                'mission_id' => $original->mission_id,
                'need_id' => $original->need_id,
                'worker_id' => $chosen->worker_id,
                'team_id' => $original->team_id,
                'role_label' => $original->role_label,
                'commercial_id' => $chosen->worker->current_commercial_id,
                'planned_start_date' => $handover->toDateString(),
                'planned_end_date' => $original->planned_end_date->toDateString(),
                'promoter_daily_rate' => $original->promoter_daily_rate,
                'worker_net_daily_rate' => $chosen->net_daily_rate,
                'salary_amount' => $original->salary_amount,
                'salary_period' => $original->salary_period,
                'z_rate' => $original->z_rate,
                'status' => AssignmentStatus::Confirmee,
                'replaces_assignment_id' => $original->getKey(),
            ]);
            $new->statusHistories()->create(['from_status' => null, 'to_status' => AssignmentStatus::Confirmee->value, 'reason' => 'Remplaçant de '.$this->attendance->name($original), ...$actor]);

            $original->statusHistories()->create(['from_status' => $original->status->value, 'to_status' => AssignmentStatus::Remplacee->value, 'reason' => $reason, ...$actor]);
            $original->forceFill(['status' => AssignmentStatus::Remplacee, 'actual_end_at' => $handover->subDay()->endOfDay(), 'end_reason' => $reason])->save();

            $chosen->update(['status' => SuggestionStatus::Selectionne]);
            Suggestion::query()->whereIn('id', $candidates->reject(fn (Suggestion $suggestion) => $suggestion->is($chosen))->modelKeys())->update(['status' => SuggestionStatus::Ecarte]);

            $worked = $original->workDays()->where('status', WorkDayStatus::Valide)->count();
            $replacement->update([
                'replacement_assignment_id' => $new->getKey(),
                'proposed_suggestion_id' => $chosen->getKey(),
                'promoter_decision' => ReplacementPromoterDecision::Accepte,
                'promoter_decided_at' => now(),
                'handover_at' => $handover,
                'financial_impact' => [
                    'worked_days' => $worked,
                    'remaining_days' => (int) $handover->diffInDays($original->planned_end_date) + 1,
                    'promoter_daily_rate' => $original->promoter_daily_rate,
                    'promoter_rate_difference' => 0,
                ],
                'status' => ReplacementStatus::Valide,
            ]);
            $this->history($replacement, ReplacementStatus::Propose, ReplacementStatus::Valide, 'Remplaçant choisi par le promoteur', $author);
            $replacement->unavailabilityRequest->update(['status' => UnavailabilityRequestStatus::Validee]);

            return $replacement->refresh();
        });
    }

    /**
     * Le promoteur refuse les remplaçants proposés : la recherche reprend.
     */
    public function refuse(Replacement $replacement, User $author, ?string $comment): Replacement
    {
        if ($replacement->status !== ReplacementStatus::Propose) {
            throw ValidationException::withMessages(['replacement' => 'Aucun remplaçant n’est proposé pour le moment.']);
        }

        return DB::transaction(function () use ($replacement, $author, $comment): Replacement {
            Suggestion::query()->whereIn('id', $this->candidates($replacement)->modelKeys())->update(['status' => SuggestionStatus::Ecarte]);
            $replacement->update([
                'promoter_decision' => ReplacementPromoterDecision::Refuse,
                'promoter_decision_reason' => $comment,
                'promoter_decided_at' => now(),
                'status' => ReplacementStatus::Recherche,
            ]);
            $this->history($replacement, ReplacementStatus::Propose, ReplacementStatus::Recherche, 'Remplaçants refusés par le promoteur', $author);
            $replacement->unavailabilityRequest->update(['status' => UnavailabilityRequestStatus::RemplacementRecherche]);

            return $replacement;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Replacement $replacement): array
    {
        $replacement->loadMissing(['originalAssignment' => fn ($query) => $query->with(SiteAttendance::ASSIGNMENT_WITH), 'replacementAssignment' => fn ($query) => $query->with(SiteAttendance::ASSIGNMENT_WITH), 'unavailabilityRequest.reason', 'mission.site']);
        $original = $replacement->originalAssignment;
        $name = $this->attendance->name($original);
        $handover = $replacement->handover_at ? CarbonImmutable::parse($replacement->handover_at) : $this->handoverDate($original);

        return [
            'id' => $replacement->id,
            'status' => ['value' => $replacement->status->value, 'label' => $replacement->status->label()],
            'decision' => ['value' => $replacement->promoter_decision->value, 'label' => $replacement->promoter_decision->label()],
            'site' => ['id' => $replacement->mission->site->id, 'title' => $replacement->mission->site->label],
            'worker' => $this->attendance->member($original),
            'reason' => $replacement->unavailabilityRequest->reason?->only(['code', 'label']),
            'comment' => $replacement->unavailabilityRequest->reason_comment,
            'handover_date' => $handover->toDateString(),
            'message' => match ($replacement->status) {
                ReplacementStatus::Signale, ReplacementStatus::Recherche => 'Nous cherchons un remplaçant disponible pour '.$name.'.',
                ReplacementStatus::Propose => 'Choisissez un remplaçant : il rejoint le chantier le '.$this->attendance->dateLabel($handover).'. Le pointage et la rémunération de '.$name.' s’arrêtent la veille au soir.',
                default => $replacement->replacementAssignment
                    ? $this->attendance->name($replacement->replacementAssignment).' remplace '.$name.' depuis le '.$this->attendance->dateLabel($handover).'.'
                    : 'Remplacement '.mb_strtolower($replacement->status->label()).'.',
            },
            'candidates' => $replacement->status === ReplacementStatus::Propose ? $this->candidates($replacement)->map(fn (Suggestion $suggestion) => $this->candidate($suggestion, $original))->values()->all() : [],
            'replacement' => $replacement->replacementAssignment ? $this->attendance->member($replacement->replacementAssignment) : null,
            'can_decide' => $replacement->status === ReplacementStatus::Propose,
            'created_at' => $replacement->created_at?->toIso8601String(),
        ];
    }

    /**
     * Jour de relève : le lendemain, ou le démarrage prévu si la mission n’a pas commencé.
     */
    public function handoverDate(Assignment $assignment): CarbonImmutable
    {
        return CarbonImmutable::tomorrow()->max(CarbonImmutable::parse($assignment->planned_start_date));
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(Suggestion $suggestion, Assignment $original): array
    {
        $worker = $suggestion->worker;
        /** @var WorkerTrade|null $trade */
        $trade = $worker->workerTrades->firstWhere('trade_id', $original->need->trade_id);

        return [
            'suggestion_id' => $suggestion->id,
            'worker_id' => $worker->id,
            'name' => trim($worker->first_name.' '.$worker->last_name),
            'initials' => mb_strtoupper(mb_substr((string) $worker->first_name, 0, 1).mb_substr((string) $worker->last_name, 0, 1)),
            'photo_url' => $worker->photoMedia ? $this->media->temporaryUrl($worker->photoMedia) : null,
            'trade' => $original->need->trade->only(['id', 'code', 'label']),
            'experience_years' => $trade?->years_experience,
            'rating' => ['average' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null, 'count' => (int) $worker->rating_count],
            'daily_rate' => $original->promoter_daily_rate,
            'available' => true,
        ];
    }

    private function history(Replacement $replacement, ?ReplacementStatus $from, ReplacementStatus $to, string $reason, User|Admin $author): void
    {
        $replacement->statusHistories()->create(['from_status' => $from?->value, 'to_status' => $to->value, 'reason' => $reason, 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
    }
}
