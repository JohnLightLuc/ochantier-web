<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fusion de deux dossiers d’ouvriers (double validation).
 */
#[Fillable([
    'source_worker_id', 'target_worker_id', 'field_choices', 'merged_by_admin_id', 'approved_by_admin_id', 'merged_at',
])]
#[WithoutTimestamps]
class WorkerMerge extends Model
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
            'field_choices' => 'array',
            'merged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function sourceWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function targetWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function mergedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
