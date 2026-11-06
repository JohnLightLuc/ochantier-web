<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\WorkerPayout;
use Illuminate\Http\Request;

/**
 * Reversement dans une liste du Back Office.
 *
 * @mixin WorkerPayout
 */
class PayoutListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'batch' => $this->batch ? ['id' => $this->batch->id, 'reference' => $this->batch->reference, 'status' => $this->status($this->batch->status)] : null,
            'worker' => ['id' => $this->worker->id, 'reference' => $this->worker->reference, 'full_name' => trim($this->worker->first_name.' '.$this->worker->last_name)],
            'site' => $this->mission->site ? ['id' => $this->mission->site->id, 'label' => $this->mission->site->label] : null,
            'mission' => ['id' => $this->mission->id, 'reference' => $this->mission->reference],
            'period_start' => $this->date($this->period_start),
            'period_end' => $this->date($this->period_end),
            'days' => (int) $this->work_days_count,
            'gross_amount' => $this->gross_amount,
            'commission_amount' => $this->commission_amount,
            'deduction_amount' => $this->deduction_amount,
            'bonus_amount' => $this->bonus_amount,
            'net_amount' => $this->net_amount,
            'number' => $this->paymentMethod?->account_number_masked,
            'operator' => $this->paymentMethod?->operator?->name,
            'status' => $this->status($this->status),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
