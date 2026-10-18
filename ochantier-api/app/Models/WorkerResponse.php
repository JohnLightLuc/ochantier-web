<?php

namespace App\Models;

use App\Enums\ResponseRecorder;
use App\Enums\WorkerResponseDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accord ou refus recueilli (consentement : date, auteur, mission, tarif net, preuve).
 */
#[Fillable([
    'suggestion_id', 'contact_id', 'worker_id', 'decision', 'reason_id', 'conditions', 'net_daily_rate_shown',
    'responded_at', 'recorded_by_type', 'recorded_by_id', 'proof_media_id', 'validated_by_admin_id', 'validated_at',
    'client_uuid',
])]
class WorkerResponse extends Model
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
            'decision' => WorkerResponseDecision::class,
            'responded_at' => 'datetime',
            'recorded_by_type' => ResponseRecorder::class,
            'validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Suggestion, $this>
     */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class);
    }

    /**
     * @return BelongsTo<WorkerOfferContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(WorkerOfferContact::class);
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
     * @return BelongsTo<Admin, $this>
     */
    public function validatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
