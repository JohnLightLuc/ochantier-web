<?php

namespace App\Models;

use App\Enums\AvailabilityType;
use Database\Factories\WorkerPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Période déclarée dans le calendrier d’un ouvrier (disponibilité, repos, indisponibilité…).
 */
#[Fillable(['starts_on', 'ends_on', 'type', 'reason', 'zone', 'comment'])]
class WorkerPeriod extends Model
{
    /** @use HasFactory<WorkerPeriodFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'type' => AvailabilityType::class,
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
