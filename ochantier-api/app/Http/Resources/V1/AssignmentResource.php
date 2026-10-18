<?php

namespace App\Http\Resources\V1;

use App\Enums\NeedAssignmentStatus;
use Illuminate\Http\Request;

/**
 * Poste proposé à un ouvrier (ou mission acceptée) sur l’offre d’un besoin. Les coordonnées du responsable
 * de chantier ne sont communiquées qu’une fois la mission acceptée.
 *
 * @mixin \App\Models\NeedAssignment
 */
class AssignmentResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $offer = $this->offer;
        $need = $offer->need;
        $site = $need->site;
        $accepted = $this->status === NeedAssignmentStatus::Validated;

        return [
            'reference' => $this->reference,
            'status' => $this->status($this->status),
            'trade' => $this->trade,
            'daily_cost' => $this->daily_cost,
            'selected_on' => $this->date($this->selected_on),
            'contacted_on' => $this->date($this->contacted_on),
            'reason' => $this->reason,
            'offer' => [
                'reference' => $offer->reference,
                'title' => $offer->title,
                'description' => $offer->description,
                'benefits' => $offer->benefits,
                'deadline' => $this->date($offer->deadline),
                'starts_on' => $this->date($offer->starts_on),
                'ends_on' => $this->date($offer->ends_on),
                'pay_type' => $offer->line?->pay_type,
                'experience' => $offer->line?->experience,
            ],
            'need' => [
                'reference' => $need->reference,
                'urgency' => $this->status($need->urgency),
                'working_hours' => $need->working_hours,
                'working_days' => $need->working_days,
                'conditions' => $need->conditions,
                'requirements' => $need->requirements,
            ],
            'site' => [
                'name' => $site->name,
                'commune' => $site->commune,
                'city' => $site->city,
                'address' => $accepted ? $site->address : null,
                'latitude' => $accepted ? $site->latitude : null,
                'longitude' => $accepted ? $site->longitude : null,
                'manager' => $accepted ? $site->manager : null,
                'manager_phone' => $accepted ? $site->manager_phone : null,
                'instructions' => $accepted ? $site->instructions : null,
                'access_conditions' => $accepted ? $site->access_conditions : null,
                'safety_equipment' => $site->safety_equipment,
            ],
        ];
    }
}
