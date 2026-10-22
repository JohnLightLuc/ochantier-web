<?php

namespace App\Models;

use App\Enums\AvailabilityConfirmationChannel;
use App\Enums\AvailabilityStatus;
use App\Enums\WorkerAvailabilitySource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique de disponibilité (source, preuve, auteur).
 */
class WorkerAvailability extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'worker_id', 'status', 'effective_from', 'effective_to', 'next_available_date', 'source', 'recorded_by_type',
        'recorded_by_id', 'confirmation_channel', 'proof_media_id', 'comment', 'client_uuid',
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
            'status' => AvailabilityStatus::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'next_available_date' => 'date',
            'source' => WorkerAvailabilitySource::class,
            'confirmation_channel' => AvailabilityConfirmationChannel::class,
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
     * @return BelongsTo<MediaFile, $this>
     */
    public function proofMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }
}
