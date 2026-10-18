<?php

namespace App\Models;

use App\Enums\ReplacementPromoterDecision;
use App\Enums\ReplacementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Remplacement d’un ouvrier indisponible.
 */
#[Fillable([
    'unavailability_request_id', 'mission_id', 'original_assignment_id', 'replacement_assignment_id',
    'requested_by_user_id', 'requested_criteria', 'proposed_suggestion_id', 'promoter_decision',
    'promoter_decision_reason', 'promoter_decided_at', 'handover_at', 'handover_instructions', 'acknowledged_at',
    'financial_impact', 'amendment_document_id', 'status', 'cancellation_reason',
])]
class Replacement extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_criteria' => 'array',
            'promoter_decision' => ReplacementPromoterDecision::class,
            'promoter_decided_at' => 'datetime',
            'handover_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'financial_impact' => 'array',
            'status' => ReplacementStatus::class,
        ];
    }

    /**
     * @return HasMany<MatchingRun, $this>
     */
    public function matchingRuns(): HasMany
    {
        return $this->hasMany(MatchingRun::class);
    }

    /**
     * @return BelongsTo<UnavailabilityRequest, $this>
     */
    public function unavailabilityRequest(): BelongsTo
    {
        return $this->belongsTo(UnavailabilityRequest::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function originalAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function replacementAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Suggestion, $this>
     */
    public function proposedSuggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function amendmentDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<ReplacementStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReplacementStatusHistory::class);
    }
}
