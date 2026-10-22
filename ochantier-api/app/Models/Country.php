<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hiérarchie géographique : pays > ville > commune > quartier.
 */
class Country extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'iso_code', 'name', 'phone_prefix', 'is_active',
    ];

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
     * Indicatifs des pays ouverts, sans « + » (« 225 ») : ceux qu’un numéro de téléphone peut porter.
     *
     * @return list<string>
     */
    public static function dialCodes(): array
    {
        return once(fn () => self::query()->where('is_active', true)->pluck('phone_prefix')
            ->map(fn (string $prefix) => ltrim($prefix, '+'))->unique()->values()->all());
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
