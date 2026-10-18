<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hiérarchie géographique : pays > ville > commune > quartier.
 */
#[Fillable([
    'iso_code', 'name', 'phone_prefix', 'is_active',
])]
class Country extends Model
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
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * @return HasMany<PaymentOperator, $this>
     */
    public function paymentOperators(): HasMany
    {
        return $this->hasMany(PaymentOperator::class);
    }
}
