<?php

namespace App\Models;

use App\Enums\ExclusionDecider;
use App\Enums\MatchingExclusionNature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ouvrier écarté d'un besoin, avec la nature et le motif de l'exclusion.
 */
#[Fillable([
    'need_id', 'worker_id', 'matching_run_id', 'nature', 'criterion', 'reason_id', 'comment', 'corrective_action',
    'decided_by_type', 'decided_by_admin_id',
])]
class MatchingExclusion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => MatchingExclusionNature::class,
            'decided_by_type' => ExclusionDecider::class,
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
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<MatchingRun, $this>
     */
    public function matchingRun(): BelongsTo
    {
        return $this->belongsTo(MatchingRun::class);
    }

    /**
     * @return BelongsTo<ReasonCode, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function decidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
