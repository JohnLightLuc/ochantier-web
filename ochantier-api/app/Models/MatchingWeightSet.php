<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Barème versionné (poids w1..w6, exclusions, règles d'équité).
 */
class MatchingWeightSet extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'version', 'weights', 'exclusion_rules', 'fairness_rules', 'reason', 'is_active', 'effective_from',
        'created_by_admin_id',
    ];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'exclusion_rules' => 'array',
            'fairness_rules' => 'array',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<MatchingCycle, $this>
     */
    public function matchingCycles(): HasMany
    {
        return $this->hasMany(MatchingCycle::class, 'weight_set_id');
    }

    /**
     * @return HasMany<MatchingRun, $this>
     */
    public function matchingRuns(): HasMany
    {
        return $this->hasMany(MatchingRun::class, 'weight_set_id');
    }
}
