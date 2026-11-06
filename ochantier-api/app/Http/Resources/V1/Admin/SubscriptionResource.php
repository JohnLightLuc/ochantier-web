<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des abonnements.
 *
 * @mixin Subscription
 */
class SubscriptionResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'promoter' => ['id' => $this->promoter_profile_id, 'name' => trim((string) $this->promoter_name) ?: 'Promoteur', 'owner' => trim((string) $this->owner_name) ?: null],
            'plan' => ['code' => $this->plan->code, 'name' => $this->plan->name, 'price' => $this->plan->price_amount, 'max_users' => $this->plan->max_users],
            'pending_plan' => $this->pendingPlan ? ['code' => $this->pendingPlan->code, 'name' => $this->pendingPlan->name, 'effective_at' => $this->date($this->pending_effective_at)] : null,
            'status' => $this->status($this->status),
            'seats_used' => (int) $this->seats_used,
            'started_at' => $this->date($this->started_at),
            'period_end' => $this->date($this->current_period_end),
            'next_due_date' => $this->auto_renew ? $this->date($this->next_due_date) : null,
            'auto_renew' => $this->auto_renew,
        ];
    }
}
