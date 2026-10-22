<?php

namespace App\Models;

use App\Enums\MatchingCycleScope;
use App\Enums\MatchingCycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cycle de matching global (plusieurs besoins, une zone ou un métier), avec ses statistiques.
 */
class MatchingCycle extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'scope', 'filters', 'weight_set_id', 'triggered_by_admin_id', 'status', 'started_at', 'finished_at',
        'duration_ms', 'stats',
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
            'scope' => MatchingCycleScope::class,
            'filters' => 'array',
            'status' => MatchingCycleStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MatchingWeightSet, $this>
     */
    public function weightSet(): BelongsTo
    {
        return $this->belongsTo(MatchingWeightSet::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function triggeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<MatchingRun, $this>
     */
    public function matchingRuns(): HasMany
    {
        return $this->hasMany(MatchingRun::class, 'cycle_id');
    }
}
