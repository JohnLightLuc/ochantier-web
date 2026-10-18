<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des statuts et des changements de formule d’un abonnement.
 */
#[Fillable([
    'subscription_id', 'from_status', 'to_status', 'from_plan_id', 'to_plan_id', 'reason', 'actor_type', 'actor_id',
])]
class SubscriptionStatusHistory extends Model
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
            'from_status' => SubscriptionStatus::class,
            'to_status' => SubscriptionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }
}
