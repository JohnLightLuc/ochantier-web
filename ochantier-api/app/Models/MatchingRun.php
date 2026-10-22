<?php

namespace App\Models;

use App\Enums\MatchingRunType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Exécution du matching pour un besoin.
 */
class MatchingRun extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'need_id', 'cycle_id', 'weight_set_id', 'run_type', 'replacement_id', 'filters', 'triggered_by_admin_id',
        'candidate_count', 'executed_at',
    ];

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_type' => MatchingRunType::class,
            'filters' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<MatchingCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(MatchingCycle::class);
    }

    /**
     * @return BelongsTo<MatchingWeightSet, $this>
     */
    public function weightSet(): BelongsTo
    {
        return $this->belongsTo(MatchingWeightSet::class);
    }

    /**
     * @return BelongsTo<Replacement, $this>
     */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Replacement::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function triggeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<Suggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    /**
     * @return HasMany<MatchingExclusion, $this>
     */
    public function matchingExclusions(): HasMany
    {
        return $this->hasMany(MatchingExclusion::class);
    }
}
