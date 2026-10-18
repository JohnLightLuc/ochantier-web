<?php

namespace App\Models;

use App\Enums\PromoterVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Organisation promotrice (entreprise) : facultative.
 */
#[Fillable([
    'owner_user_id', 'legal_name', 'trade_name', 'legal_form', 'rccm', 'tax_identifier', 'address', 'commune_id',
    'phone', 'email', 'legal_representative', 'logo_media_id', 'billing_name', 'billing_address',
    'verification_status', 'validated_at', 'validated_by_admin_id', 'suspended_reason',
])]
class Organization extends Model
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
            'verification_status' => PromoterVerificationStatus::class,
            'validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function logoMedia(): BelongsTo
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

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
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
