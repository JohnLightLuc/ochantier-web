<?php

namespace App\Models;

use App\Enums\UnavailabilityDeclarant;
use App\Enums\UnavailabilityRequestPhase;
use App\Enums\UnavailabilityRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Indisponibilité d’un ouvrier affecté, déclarée avant ou pendant la mission.
 */
class UnavailabilityRequest extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'assignment_id', 'mission_id', 'worker_id', 'phase', 'declared_by_type', 'declared_by_id', 'effective_at',
        'last_worked_date', 'reason_id', 'reason_comment', 'estimated_duration_days', 'is_urgent', 'proof_media_id',
        'status', 'cancellation_reason', 'client_uuid',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => UnavailabilityRequestPhase::class,
            'declared_by_type' => UnavailabilityDeclarant::class,
            'effective_at' => 'datetime',
            'last_worked_date' => 'date',
            'is_urgent' => 'boolean',
            'status' => UnavailabilityRequestStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
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
     * @return BelongsTo<MediaFile, $this>
     */
    public function proofMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return HasMany<Replacement, $this>
     */
    public function replacements(): HasMany
    {
        return $this->hasMany(Replacement::class);
    }
}
