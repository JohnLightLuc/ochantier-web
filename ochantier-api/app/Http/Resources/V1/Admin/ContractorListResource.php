<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Contractor;
use App\Support\Contractor\ContractorAccount;
use App\Support\Contractor\ContractorSubscriptions;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des entrepreneurs du Back Office.
 *
 * @mixin Contractor
 */
class ContractorListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name ?: null,
            'kind' => $this->status($this->kind),
            'phone' => PhoneNumber::of($this->resource)?->toArray()['phone'],
            'email' => $this->email,
            'city' => $this->city?->only(['id', 'name']),
            'trades' => $this->trades->map->only(['code', 'label'])->values(),
            'zones' => $this->whenLoaded('zones', fn () => $this->zones->sortBy('name')->pluck('name')->values()),
            'plan' => $this->currentSubscription
                ? ['code' => $this->currentSubscription->plan->code, 'name' => $this->currentSubscription->plan->name, 'max_users' => $this->currentSubscription->plan->max_users]
                : ['code' => ContractorSubscriptions::FREE_PLAN, 'name' => 'Solo', 'max_users' => 1],
            'activity' => [
                'open' => (int) $this->open_quote_bids_count + (int) $this->open_offer_bids_count,
                'total' => (int) $this->quote_bids_count + (int) $this->offer_bids_count,
            ],
            'rating' => ['average' => $this->rating_avg !== null ? (float) $this->rating_avg : null, 'count' => $this->rating_count],
            'status' => $this->status($this->status),
            'verification_status' => $this->status($this->verification_status),
            'verification_state' => app(ContractorAccount::class)->state($this->resource),
            'has_account' => $this->user_id !== null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
