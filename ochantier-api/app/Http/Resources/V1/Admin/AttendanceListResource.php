<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\AttendanceEvent;
use App\Models\WorkDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des pointages du Back Office : un ouvrier, un jour.
 *
 * @mixin WorkDay
 */
class AttendanceListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $worker = $this->worker;
        $site = $this->mission->site;
        $user = $site->promoterProfile?->user;
        $organization = $site->organization;

        return [
            'id' => $this->id,
            'date' => $this->date($this->work_date),
            'status' => $this->status($this->status),
            'worker' => ['id' => $worker->id, 'reference' => $worker->reference, 'full_name' => trim($worker->first_name.' '.$worker->last_name)],
            'site' => ['id' => $site->id, 'reference' => $site->reference, 'label' => $site->label, 'commune' => $site->commune?->name],
            'promoter' => ['name' => $organization ? ($organization->trade_name ?: $organization->legal_name) : trim($user?->first_name.' '.$user?->last_name)],
            'trade' => $this->assignment->need->trade?->label,
            'arrival' => $this->time($this->arrivalEvent),
            'departure' => $this->time($this->departureEvent),
            'worked_minutes' => $this->worked_minutes,
            'out_of_zone' => (bool) ($this->arrivalEvent?->out_of_zone || $this->departureEvent?->out_of_zone),
            'open_anomalies' => (int) $this->open_anomalies,
            'is_locked' => (bool) $this->is_locked,
        ];
    }

    protected function time(?AttendanceEvent $event): ?string
    {
        return $event ? CarbonImmutable::parse($event->device_recorded_at ?? $event->recorded_at)->setTimezone(config('app.timezone'))->format('H:i') : null;
    }
}
