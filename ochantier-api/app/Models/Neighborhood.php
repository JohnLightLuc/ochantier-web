<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quartier d’une commune.
 */
#[Fillable([
    'commune_id', 'name', 'location', 'is_active',
])]
class Neighborhood extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return HasMany<PromoterProfile, $this>
     */
    public function promoterProfiles(): HasMany
    {
        return $this->hasMany(PromoterProfile::class);
    }

    /**
     * @return HasMany<Prospect, $this>
     */
    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class);
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
