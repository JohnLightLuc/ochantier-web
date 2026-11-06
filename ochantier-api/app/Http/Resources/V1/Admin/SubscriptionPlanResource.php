<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\SubscriptionPlan;
use App\Support\Promoter\PromoterSubscriptions;
use Illuminate\Http\Request;

/**
 * Formule du catalogue, avec ses abonnés.
 *
 * @mixin SubscriptionPlan
 */
class SubscriptionPlanResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'price' => $this->price_amount,
            'max_users' => $this->max_users,
            'features' => $this->features ?? [],
            'is_featured' => $this->is_featured,
            'is_active' => $this->is_active,
            'is_free' => $this->code === PromoterSubscriptions::FREE_PLAN,
            'subscribers' => (int) ($this->subscribers_count ?? 0),
            'monthly_revenue' => (int) ($this->paying_count ?? 0) * $this->price_amount,
        ];
    }
}
