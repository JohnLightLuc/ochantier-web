<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\PaymentScheduleStatus;
use App\Http\Resources\V1\Resource;
use App\Models\PaymentSchedule;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des échéances du Back Office : une échéance d’une mission, avec son état du jour.
 *
 * @mixin PaymentSchedule
 */
class ScheduleListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mission = $this->mission;
        $site = $mission->site;
        $user = $site->promoterProfile?->user;
        $organization = $site->organization;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'state' => $this->status(PaymentScheduleStatus::from($this->state)),
            'type' => $this->status($this->schedule_type),
            'sequence' => $this->sequence,
            'site' => ['id' => $site->id, 'reference' => $site->reference, 'label' => $site->label],
            'promoter' => ['name' => $organization ? ($organization->trade_name ?: $organization->legal_name) : trim($user?->first_name.' '.$user?->last_name)],
            'trade' => $mission->need?->trade?->label,
            'period' => $this->period_start ? ['start' => $this->date($this->period_start), 'end' => $this->date($this->period_end)] : null,
            'due_date' => $this->effective_due ? substr((string) $this->effective_due, 0, 10) : null,
            'is_flexible' => (bool) $this->is_flexible,
            'amount_due' => $this->amount_due,
            'amount_paid' => $this->amount_paid,
            'remaining' => $this->amount_due - $this->amount_paid,
        ];
    }
}
