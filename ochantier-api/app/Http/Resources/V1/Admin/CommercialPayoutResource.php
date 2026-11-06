<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\CommercialPayoutStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use App\Models\CommercialAdjustment;
use App\Models\CommercialEarning;
use App\Models\CommercialPayout;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;

/**
 * Versement à un commercial, avec son détail quand il est chargé.
 *
 * @mixin CommercialPayout
 */
class CommercialPayoutResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $other = $this->created_by_admin_id !== $admin->getKey();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'commercial' => ['id' => $this->commercial->id, 'reference' => $this->commercial->employee_number, 'full_name' => trim($this->commercial->user->first_name.' '.$this->commercial->user->last_name)],
            'period_start' => $this->date($this->period_start),
            'period_end' => $this->date($this->period_end),
            'amount' => $this->total_amount,
            'status' => $this->status($this->status),
            'prepared_by' => $this->name($this->createdByAdmin),
            'approved_by' => $this->name($this->approvedByAdmin),
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'can_approve' => $this->status === CommercialPayoutStatus::APayer && $other,
            'can_retry' => $this->status === CommercialPayoutStatus::Rejete && $other,
            'can_cancel' => in_array($this->status, [CommercialPayoutStatus::APayer, CommercialPayoutStatus::Rejete], true),
            ...$this->relationLoaded('commercialEarnings') ? [
                'earnings' => $this->commercialEarnings->map(fn (CommercialEarning $earning) => ['id' => $earning->id, 'worker' => $earning->worker ? trim($earning->worker->first_name.' '.$earning->worker->last_name) : null, 'amount' => $earning->amount, 'date' => $earning->event_at?->toIso8601String()])->values()->all(),
                'adjustments' => $this->commercialAdjustments->map(fn (CommercialAdjustment $adjustment) => ['id' => $adjustment->id, 'kind' => $this->status($adjustment->kind), 'amount' => $adjustment->amount, 'reason' => $adjustment->reason])->values()->all(),
                'transactions' => $this->paymentTransactions->sortBy('initiated_at')->values()->map(fn (PaymentTransaction $transaction) => ['id' => $transaction->id, 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'operator' => $transaction->operator?->name, 'number' => $transaction->metadata['phone'] ?? null, 'status' => $this->status($transaction->status), 'failure' => $transaction->failure_message, 'initiated_at' => $transaction->initiated_at?->toIso8601String()])->all(),
            ] : [],
        ];
    }

    private function name(?Admin $admin): ?string
    {
        return $admin ? trim($admin->first_name.' '.$admin->last_name) : null;
    }
}
