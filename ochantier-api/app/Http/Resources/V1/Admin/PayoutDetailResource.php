<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\PayoutAdjustmentStatus;
use App\Enums\WorkerPayoutBatchStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\Admin;
use App\Models\PaymentTransaction;
use App\Models\PayoutAdjustment;
use App\Models\WorkDay;
use App\Models\WorkerPayout;
use Illuminate\Http\Request;

/**
 * Fiche d’un reversement : journées payées, retenues et bonus, envois au prestataire.
 *
 * @mixin WorkerPayout
 */
class PayoutDetailResource extends PayoutListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Admin $admin */
        $admin = $request->user();
        $inDraft = $this->status === WorkerPayoutStatus::Provisionne && $this->batch?->status === WorkerPayoutBatchStatus::Brouillon;
        $rejected = $this->status === WorkerPayoutStatus::Rejete;

        return [
            ...parent::toArray($request),
            'schedule' => $this->paymentSchedule ? ['id' => $this->paymentSchedule->id, 'reference' => $this->paymentSchedule->reference] : null,
            'prepared_by' => $this->name($this->createdByAdmin),
            'approved_by' => $this->name($this->approvedByAdmin),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'work_days' => $this->workDays->sortBy('work_date')->values()->map(fn (WorkDay $day) => ['id' => $day->id, 'date' => $this->date($day->work_date), 'worked_minutes' => $day->worked_minutes])->all(),
            'adjustments' => $this->payoutAdjustments->sortByDesc('created_at')->values()->map(fn (PayoutAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'kind' => $this->status($adjustment->kind),
                'amount' => $adjustment->amount,
                'reason' => $adjustment->reason,
                'basis' => $adjustment->basis,
                'status' => $this->status($adjustment->status),
                'proposed_by' => $this->name($adjustment->createdByAdmin),
                'decided_by' => $this->name($adjustment->approvedByAdmin),
                'can_decide' => $inDraft && $adjustment->status === PayoutAdjustmentStatus::Proposee && $adjustment->created_by_admin_id !== $admin->getKey(),
            ])->all(),
            'transactions' => $this->paymentTransactions->sortBy('initiated_at')->values()->map(fn (PaymentTransaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => $transaction->amount,
                'operator' => $transaction->operator?->name,
                'number' => $transaction->metadata['phone'] ?? null,
                'status' => $this->status($transaction->status),
                'failure' => $transaction->failure_message,
                'initiated_at' => $transaction->initiated_at?->toIso8601String(),
            ])->all(),
            'can_adjust' => $inDraft,
            'can_cancel' => $inDraft || $rejected,
            'can_retry' => $rejected && $this->created_by_admin_id !== $admin->getKey(),
        ];
    }

    private function name(?Admin $admin): ?string
    {
        return $admin ? trim($admin->first_name.' '.$admin->last_name) : null;
    }
}
