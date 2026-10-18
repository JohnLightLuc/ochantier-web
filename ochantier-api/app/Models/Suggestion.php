<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suggestion = un ouvrier classé pour un besoin, avec son explication.
 */
#[Fillable([
    'matching_run_id', 'need_id', 'worker_id', 'rank', 'score', 'score_factors', 'distance_km', 'worker_daily_rate',
    'promoter_daily_rate', 'net_daily_rate', 'is_manual', 'added_by_admin_id', 'status', 'published_to_promoter_at',
    'published_by_admin_id',
])]
class Suggestion extends Model
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
            'score' => 'decimal:3',
            'score_factors' => 'array',
            'distance_km' => 'decimal:2',
            'is_manual' => 'boolean',
            'status' => SuggestionStatus::class,
            'published_to_promoter_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MatchingRun, $this>
     */
    public function matchingRun(): BelongsTo
    {
        return $this->belongsTo(MatchingRun::class);
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function addedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function publishedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<WorkerOfferContact, $this>
     */
    public function workerOfferContacts(): HasMany
    {
        return $this->hasMany(WorkerOfferContact::class);
    }

    /**
     * @return HasMany<WorkerResponse, $this>
     */
    public function workerResponses(): HasMany
    {
        return $this->hasMany(WorkerResponse::class);
    }

    /**
     * @return HasMany<Selection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class);
    }

    /**
     * @return HasMany<SuggestionScoreHistory, $this>
     */
    public function scoreHistories(): HasMany
    {
        return $this->hasMany(SuggestionScoreHistory::class);
    }
}
