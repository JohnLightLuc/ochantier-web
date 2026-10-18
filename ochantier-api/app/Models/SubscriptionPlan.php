<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formules d'abonnement des promoteurs (distinctes de la commission Y).
 */
#[Fillable([
    'code', 'name', 'subtitle', 'description', 'price_amount', 'billing_period', 'max_users', 'features',
    'is_featured', 'sort_order', 'is_active',
])]
class SubscriptionPlan extends Model
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
            'billing_period' => BillingPeriod::class,
            'features' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    /**
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class, 'plan_id');
    }
}
