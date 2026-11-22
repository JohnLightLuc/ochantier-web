<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Opérateurs Mobile Money / prestataires de paiement.
 */
class PaymentOperator extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code', 'name', 'country_id', 'provider', 'number_pattern', 'supports_collection', 'supports_payout', 'is_active', 'collection_fee_percent',
        'min_amount', 'max_amount', 'sort_order', 'notice',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supports_collection' => 'boolean',
            'supports_payout' => 'boolean',
            'is_active' => 'boolean',
            'collection_fee_percent' => 'decimal:2',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Opérateur tel qu’il est proposé au payeur (promoteur ou entrepreneur).
     *
     * @return array{code: string, name: string, fee_percent: float, number_pattern: string|null, min_amount: int|null, max_amount: int|null, notice: string|null}
     */
    public function forPayer(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'fee_percent' => (float) $this->collection_fee_percent,
            'number_pattern' => $this->number_pattern,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'notice' => $this->notice,
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'operator_id');
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'operator_id');
    }
}
