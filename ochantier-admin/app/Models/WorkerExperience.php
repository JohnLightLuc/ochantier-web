<?php

namespace App\Models;

use Database\Factories\WorkerExperienceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expérience professionnelle déclarée par un ouvrier.
 */
#[Fillable(['company', 'mission', 'trade', 'site', 'location', 'started_at', 'ended_at', 'description', 'reference'])]
class WorkerExperience extends Model
{
    /** @use HasFactory<WorkerExperienceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at' => 'date',
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
