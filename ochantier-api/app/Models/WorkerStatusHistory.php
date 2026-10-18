<?php

namespace App\Models;

use App\Enums\WorkerVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des statuts de dossier d’un ouvrier.
 */
#[Fillable([
    'worker_id', 'from_status', 'to_status', 'reason', 'actor_type', 'actor_id',
])]
class WorkerStatusHistory extends Model
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
            'from_status' => WorkerVerificationStatus::class,
            'to_status' => WorkerVerificationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
