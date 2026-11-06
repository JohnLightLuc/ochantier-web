<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\RefundStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Support\Admin\PayoutMethods;
use App\Support\Admin\Refunds;
use Illuminate\Http\Request;

/**
 * Remboursement au promoteur, avec son détail quand il est chargé.
 *
 * @mixin Refund
 */
class RefundResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $refunds = app(Refunds::class);
        $method = $this->paymentMethod;
        $user = $this->mission->site->promoterProfile->user;
        $excluded = [$this->requested_by_admin_id, $this->approved_by_admin_id, $method?->created_by_id];
        $canDecide = $method !== null && ! in_array($admin->getKey(), $excluded, true);
        $open = in_array($this->status, [RefundStatus::Demande, RefundStatus::Echoue], true);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'origin' => $this->status($this->origin),
            'status' => $this->status($this->status),
            'amount' => $this->amount,
            'reason' => $this->reason,
            'refusal_reason' => $this->refusal_reason,
            'mission' => ['id' => $this->mission->id, 'reference' => $this->mission->reference],
            'site' => ['id' => $this->mission->site->id, 'label' => $this->mission->site->label],
            'promoter' => ['id' => $this->beneficiary_id, 'full_name' => trim($user->first_name.' '.$user->last_name)],
            'transaction' => $this->originalTransaction ? ['id' => $this->originalTransaction->id, 'reference' => $this->originalTransaction->reference, 'amount' => $this->originalTransaction->amount] : null,
            'number' => $method?->account_number_masked,
            'operator' => $method?->operator?->name,
            'requested_by' => $this->name($this->requestedByAdmin),
            'approved_by' => $this->name($this->approvedByAdmin),
            'second_approver' => $this->name($this->secondApproverAdmin),
            'approvals_required' => $refunds->needsSecondApproval($this->resource) ? 2 : 1,
            'created_at' => $this->created_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'can_approve' => $this->status === RefundStatus::Demande && $canDecide,
            'can_retry' => $this->status === RefundStatus::Echoue && $canDecide,
            'can_refuse' => $open,
            'can_change_number' => $open,
            ...$this->relationLoaded('paymentTransactions') ? [
                'saved_numbers' => $open ? $refunds->savedNumbers($this->resource)->map(fn (PaymentMethod $saved) => ['id' => $saved->id, 'operator' => $saved->operator?->name, 'number' => $saved->account_number_masked])->values()->all() : [],
                'operators' => $open ? app(PayoutMethods::class)->operators() : [],
                'transactions' => $this->paymentTransactions->sortBy('initiated_at')->values()->map(fn (PaymentTransaction $transaction) => ['id' => $transaction->id, 'reference' => $transaction->reference, 'amount' => $transaction->amount, 'operator' => $transaction->operator?->name, 'number' => $transaction->metadata['phone'] ?? null, 'status' => $this->status($transaction->status), 'failure' => $transaction->failure_message, 'initiated_at' => $transaction->initiated_at?->toIso8601String()])->all(),
            ] : [],
        ];
    }

    private function name(?Admin $admin): ?string
    {
        return $admin ? trim($admin->first_name.' '.$admin->last_name) : null;
    }
}
