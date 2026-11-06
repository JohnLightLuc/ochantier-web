<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\PaymentInstallmentStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\WorkDayStatus;
use App\Models\PaymentAllocation;
use App\Models\PaymentInstallment;
use App\Models\PaymentSchedule;
use App\Models\WorkDay;
use Illuminate\Http\Request;

/**
 * Fiche d’une échéance du Back Office : décomposition du montant, paiements reçus, versements d’un paiement flexible et
 * journées pointées sur la période.
 *
 * @mixin PaymentSchedule
 */
class ScheduleDetailResource extends ScheduleListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = PaymentScheduleStatus::from($this->state);

        return [
            ...parent::toArray($request),
            'mission_id' => $this->mission_id,
            'workers_amount' => $this->workers_amount,
            'promoter_commission_amount' => $this->promoter_commission_amount,
            'deposit_deduction_amount' => $this->deposit_deduction_amount,
            'adjustment_amount' => $this->adjustment_amount,
            'last_reminder_at' => $this->last_reminder_at?->toIso8601String(),
            'days' => $this->days(),
            'payments' => $this->allocations->map(fn (PaymentAllocation $allocation) => [
                'amount' => $allocation->amount,
                'transaction' => ['id' => $allocation->transaction->id, 'reference' => $allocation->transaction->reference, 'operator' => $allocation->transaction->operator?->name, 'status' => $this->status($allocation->transaction->status), 'completed_at' => $allocation->transaction->completed_at?->toIso8601String()],
            ])->filter(fn (array $row) => $row['transaction']['status']['value'] === PaymentTransactionStatus::Reussie->value)->values()->all(),
            'installments' => PaymentInstallment::query()->whereJsonContains('schedule_ids', $this->id)->orderBy('sequence')->get()->map(fn (PaymentInstallment $installment) => [
                'sequence' => $installment->sequence,
                'count' => $installment->installments_count,
                'amount' => $installment->amount,
                'due_date' => $this->date($installment->due_date),
                'status' => $this->status($installment->status),
                'is_open' => $installment->status === PaymentInstallmentStatus::APayer,
            ])->all(),
            'can' => ['remind' => in_array($state, [PaymentScheduleStatus::EnRetard, PaymentScheduleStatus::Exigible, PaymentScheduleStatus::PartiellementPayee], true)],
        ];
    }

    /**
     * Journées de la période, tous ouvriers de la mission confondus. Elles ne changent pas le montant de l’échéance.
     *
     * @return array{validated: int, pending: int}|null
     */
    private function days(): ?array
    {
        if (! $this->period_start) {
            return null;
        }

        $days = WorkDay::query()->where('mission_id', $this->mission_id)->whereBetween('work_date', [$this->period_start->toDateString(), $this->period_end->toDateString()])->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'validated' => (int) $days->get(WorkDayStatus::Valide->value, 0),
            'pending' => (int) $days->get(WorkDayStatus::AValider->value, 0) + (int) $days->get(WorkDayStatus::EnCours->value, 0),
        ];
    }
}
