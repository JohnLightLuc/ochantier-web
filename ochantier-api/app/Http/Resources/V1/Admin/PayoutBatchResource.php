<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\PayoutAdjustmentStatus;
use App\Enums\WorkerPayoutBatchStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use App\Models\WorkerPayoutBatch;
use Illuminate\Http\Request;

/**
 * Lot de reversements, avec le détail de ses reversements sur sa fiche.
 *
 * @mixin WorkerPayoutBatch
 */
class PayoutBatchResource extends Resource
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
            'status' => $this->status($this->status),
            'payout_count' => $this->payout_count,
            'total_net_amount' => $this->total_net_amount,
            'total_commission_amount' => $this->total_commission_amount,
            'results' => $this->results ?: null,
            'prepared_by' => $this->name($this->createdByAdmin),
            'approved_by' => $this->name($this->approvedByAdmin),
            'created_at' => $this->created_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'can_submit' => $this->status === WorkerPayoutBatchStatus::Brouillon,
            'can_cancel' => in_array($this->status, [WorkerPayoutBatchStatus::Brouillon, WorkerPayoutBatchStatus::AValider], true),
            'can_approve' => $this->status === WorkerPayoutBatchStatus::AValider && $other,
            'can_resume' => $this->status === WorkerPayoutBatchStatus::EnExecution && $other,
            ...$this->relationLoaded('workerPayouts') ? [
                'pending_adjustments' => $this->workerPayouts->sum(fn ($payout) => $payout->payoutAdjustments->where('status', PayoutAdjustmentStatus::Proposee)->count()),
                'payouts' => PayoutListResource::collection($this->workerPayouts)->resolve($request),
            ] : [],
        ];
    }

    private function name(?Admin $admin): ?string
    {
        return $admin ? trim($admin->first_name.' '.$admin->last_name) : null;
    }
}
