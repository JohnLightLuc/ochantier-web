<?php

namespace App\Models;

use App\Enums\SanctionAppealStatus;
use App\Enums\WorkerSanctionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avertissement, suspension ou exclusion d’un ouvrier.
 */
#[Fillable([
    'worker_id', 'sanction_type', 'reason_id', 'comment', 'starts_at', 'ends_at', 'decided_by_admin_id',
    'appeal_status', 'lifted_at',
])]
class WorkerSanction extends Model
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
            'sanction_type' => WorkerSanctionType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'appeal_status' => SanctionAppealStatus::class,
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
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
