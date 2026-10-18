<?php

namespace App\Models;

use App\Enums\ContactChannel;
use App\Enums\ContactStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contact de l'ouvrier : appel du commercial ou notification (pas d'application en V1).
 */
#[Fillable([
    'suggestion_id', 'worker_id', 'commercial_id', 'channel', 'contacted_by_type', 'contacted_by_id', 'contacted_at',
    'response_deadline_at', 'reminder_count', 'last_reminder_at', 'status', 'client_uuid',
])]
class WorkerOfferContact extends Model
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
            'channel' => ContactChannel::class,
            'contacted_at' => 'datetime',
            'response_deadline_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'status' => ContactStatus::class,
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
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return HasMany<WorkerResponse, $this>
     */
    public function workerResponses(): HasMany
    {
        return $this->hasMany(WorkerResponse::class, 'contact_id');
    }
}
