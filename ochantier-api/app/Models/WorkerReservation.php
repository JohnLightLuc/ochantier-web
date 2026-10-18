<?php

namespace App\Models;

use App\Enums\WorkerReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réservation provisoire pendant le paiement de l'acompte.
 */
#[Fillable([
    'worker_id', 'need_id', 'selection_id', 'period', 'reserved_at', 'expires_at', 'released_at', 'status',
])]
#[WithoutTimestamps]
class WorkerReservation extends Model
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
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'status' => WorkerReservationStatus::class,
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
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<Selection, $this>
     */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class);
    }
}
