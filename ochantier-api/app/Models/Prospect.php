<?php

namespace App\Models;

use App\Enums\ProspectInterestLevel;
use App\Enums\ProspectStatus;
use App\Enums\WorkerVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ouvrier prospecté par un commercial, avant la création de son dossier.
 */
#[Fillable([
    'commercial_id', 'first_name', 'last_name', 'dial_code', 'phone_number', 'presumed_trade_id', 'commune_id', 'neighborhood_id',
    'estimated_experience_years', 'interest_level', 'has_documents', 'availability_note', 'status',
    'contact_consent_at', 'abandon_reason_id', 'abandon_comment', 'converted_worker_id', 'converted_at',
    'client_uuid', 'lock_version',
])]
class Prospect extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interest_level' => ProspectInterestLevel::class,
            'has_documents' => 'boolean',
            'status' => ProspectStatus::class,
            'contact_consent_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function presumedTrade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return BelongsTo<Neighborhood, $this>
     */
    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    /**
     * @return BelongsTo<ReasonCode, $this>
     */
    public function abandonReason(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function convertedWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return HasMany<ProspectFollowup, $this>
     */
    public function followups(): HasMany
    {
        return $this->hasMany(ProspectFollowup::class);
    }

    /**
     * @return HasMany<Worker, $this>
     */
    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /**
     * Dossier ouvrier en cours de saisie par le commercial (un seul à la fois).
     *
     * @return HasOne<Worker, $this>
     */
    public function draftWorker(): HasOne
    {
        return $this->hasOne(Worker::class)->where('verification_status', WorkerVerificationStatus::Brouillon);
    }

    /**
     * @return MorphMany<Consent, $this>
     */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'subject');
    }
}
