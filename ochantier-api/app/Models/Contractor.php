<?php

namespace App\Models;

use App\Casts\GeoPoint;
use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Entrepreneur (entreprise constituée ou indépendant) qui répond aux offres contractuelles.
 */
class Contractor extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'kind', 'name', 'contact_name', 'user_id', 'dial_code', 'phone_number', 'email', 'commune_id',
        'location', 'team_size', 'completed_sites_count', 'rating_avg', 'rating_count', 'logo_media_id', 'status',
        'legal_form', 'rccm', 'tax_identifier', 'birth_date', 'city_id', 'address', 'experience_years', 'description',
        'verification_status', 'submitted_at', 'validated_at', 'validated_by_admin_id', 'verification_reason', 'suspended_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ContractorKind::class,
            'location' => GeoPoint::class,
            'status' => ContractorStatus::class,
            'team_size' => 'integer',
            'completed_sites_count' => 'integer',
            'rating_count' => 'integer',
            'birth_date' => 'date',
            'experience_years' => 'integer',
            'verification_status' => ContractorVerificationStatus::class,
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
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
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * Ville principale d’intervention.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Logo de l’entreprise ou photo de profil de l’indépendant.
     *
     * @return BelongsTo<MediaFile, $this>
     */
    public function logoMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return HasMany<ContractorBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(ContractorBid::class);
    }

    /**
     * Métiers exercés.
     *
     * @return BelongsToMany<Trade, $this>
     */
    public function trades(): BelongsToMany
    {
        return $this->belongsToMany(Trade::class, 'contractor_trades');
    }

    /**
     * Villes d’intervention en plus de la ville principale.
     *
     * @return BelongsToMany<City, $this>
     */
    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'contractor_zones');
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /**
     * @return MorphMany<VerificationReview, $this>
     */
    public function verificationReviews(): MorphMany
    {
        return $this->morphMany(VerificationReview::class, 'subject');
    }

    public function isCompany(): bool
    {
        return $this->kind === ContractorKind::Entreprise;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === ContractorVerificationStatus::Valide && $this->status === ContractorStatus::Actif;
    }
}
