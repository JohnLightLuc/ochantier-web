<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\Need;
use App\Models\Site;
use App\Support\Admin\SiteOperations;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Fiche d’un chantier : localisation, calendrier, promoteur, besoins et décisions possibles.
 *
 * @mixin Site
 */
class SiteDetailResource extends SiteListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->promoterProfile?->user;
        $responsible = $this->responsibleUser;
        $suspension = $this->status?->value === 'suspendu' ? $this->statusHistories()->where('to_status', 'suspendu')->latest()->latest('id')->first() : null;
        $archived = $this->archived_at !== null;

        return [
            ...parent::toArray($request),
            'internal_reference' => $this->internal_reference,
            'description' => $this->description,
            'address' => $this->address,
            'neighborhood' => $this->neighborhood?->only(['id', 'name']),
            'access_instructions' => $this->access_instructions,
            'location' => $this->location,
            'geofence_radius_m' => $this->geofence_radius_m,
            'planned_duration_days' => $this->planned_duration_days,
            'work_hours' => ['start' => $this->work_start_time, 'end' => $this->work_end_time],
            'contact' => ['full_name' => trim($user?->first_name.' '.$user?->last_name), 'phone' => $user ? PhoneNumber::of($user)?->formatted() : null, 'email' => $user?->email],
            'responsible' => $responsible ? ['full_name' => trim($responsible->first_name.' '.$responsible->last_name), 'phone' => PhoneNumber::of($responsible)?->formatted()] : null,
            'needs' => $this->needs->map(fn (Need $need) => ['id' => $need->id, 'trade' => $need->trade?->label, 'headcount' => $need->headcount, 'start_date' => $this->date($need->start_date), 'status' => $this->status($need->status)])->values()->all(),
            'suspension_reason' => $suspension?->reason,
            'cancellation_reason' => $this->cancellation_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $this->closedByAdmin ? trim($this->closedByAdmin->first_name.' '.$this->closedByAdmin->last_name) : null,
            'can' => [
                'suspend' => ! $archived && in_array($this->status, SiteOperations::SUSPENDABLE, true),
                'resume' => ! $archived && $this->status?->value === 'suspendu',
                'cancel' => ! $archived && in_array($this->status, SiteOperations::CANCELLABLE, true),
                'close' => ! $archived && in_array($this->status, SiteOperations::CLOSABLE, true),
                'archive' => ! $archived && in_array($this->status, SiteOperations::ARCHIVABLE, true),
            ],
        ];
    }
}
