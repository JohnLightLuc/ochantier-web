<?php

namespace App\Models;

use App\Enums\CommercialProfileStatus;
use App\Enums\CommercialProfileVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Profil d’un commercial terrain : matricule, superviseur, statut.
 */
#[Fillable([
    'user_id', 'employee_number', 'supervisor_id', 'is_supervisor', 'photo_media_id', 'address', 'secondary_phone',
    'verification_status', 'status', 'started_at', 'ended_at', 'created_by_admin_id',
])]
class CommercialProfile extends Model
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
            'is_supervisor' => 'boolean',
            'verification_status' => CommercialProfileVerificationStatus::class,
            'status' => CommercialProfileStatus::class,
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function photoMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<CommercialZone, $this>
     */
    public function commercialZones(): HasMany
    {
        return $this->hasMany(CommercialZone::class, 'commercial_id');
    }

    /**
     * @return HasMany<CommercialObjective, $this>
     */
    public function commercialObjectives(): HasMany
    {
        return $this->hasMany(CommercialObjective::class, 'commercial_id');
    }

    /**
     * @return HasMany<Prospect, $this>
     */
    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'commercial_id');
    }

    /**
     * @return HasMany<ProspectFollowup, $this>
     */
    public function prospectFollowups(): HasMany
    {
        return $this->hasMany(ProspectFollowup::class, 'commercial_id');
    }

    /**
     * @return HasMany<WorkerOfferContact, $this>
     */
    public function workerOfferContacts(): HasMany
    {
        return $this->hasMany(WorkerOfferContact::class, 'commercial_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'commercial_id');
    }

    /**
     * @return HasMany<CommercialPayout, $this>
     */
    public function commercialPayouts(): HasMany
    {
        return $this->hasMany(CommercialPayout::class, 'commercial_id');
    }

    /**
     * @return HasMany<CommercialEarning, $this>
     */
    public function commercialEarnings(): HasMany
    {
        return $this->hasMany(CommercialEarning::class, 'commercial_id');
    }

    /**
     * @return HasMany<CommercialAdjustment, $this>
     */
    public function commercialAdjustments(): HasMany
    {
        return $this->hasMany(CommercialAdjustment::class, 'commercial_id');
    }

    /**
     * @return HasMany<CommercialRankingEntry, $this>
     */
    public function commercialRankingEntries(): HasMany
    {
        return $this->hasMany(CommercialRankingEntry::class, 'commercial_id');
    }

    /**
     * @return HasMany<CommercialAppreciation, $this>
     */
    public function commercialAppreciations(): HasMany
    {
        return $this->hasMany(CommercialAppreciation::class, 'commercial_id');
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /**
     * @return MorphMany<PaymentMethod, $this>
     */
    public function paymentMethods(): MorphMany
    {
        return $this->morphMany(PaymentMethod::class, 'owner');
    }

    /**
     * @return MorphMany<VerificationReview, $this>
     */
    public function verificationReviews(): MorphMany
    {
        return $this->morphMany(VerificationReview::class, 'subject');
    }
}
