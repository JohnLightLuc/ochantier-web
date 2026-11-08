<?php

namespace App\Models;

use App\Casts\GeoPoint;
use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
