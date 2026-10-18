<?php

namespace App\Models;

use App\Enums\OfferType;
use App\Enums\PriceOrigin;
use App\Enums\PricingSnapshotKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Instantané tarifaire : simulations, devis, puis valeurs figées à la confirmation.
 */
#[Fillable([
    'need_id', 'kind', 'offer_type', 'price_origin', 'x_rate', 'y_rate', 'z_rate', 'r_rate', 'parameter_ids',
    'subscription_id', 'headcount', 'planned_days', 'workers_base_amount', 'promoter_commission_amount',
    'worker_commission_amount', 'total_promoter_amount', 'deposit_amount', 'balance_amount', 'rounding_rule', 'lines',
    'computed_by_type', 'computed_by_id',
])]
class PricingSnapshot extends Model
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
            'kind' => PricingSnapshotKind::class,
            'offer_type' => OfferType::class,
            'price_origin' => PriceOrigin::class,
            'x_rate' => 'decimal:4',
            'y_rate' => 'decimal:4',
            'z_rate' => 'decimal:4',
            'r_rate' => 'decimal:4',
            'parameter_ids' => 'array',
            'lines' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<Mission, $this>
     */
    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    /**
     * @return HasMany<PaymentSchedule, $this>
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }
}
