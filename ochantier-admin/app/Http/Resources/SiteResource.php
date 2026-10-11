<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chantier complet (fiche).
 *
 * @mixin Site
 */
class SiteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'reference' => $this->reference,
            'name' => $this->name,
            'promoter' => $this->promoter_name,
            'commune' => $this->commune,
            'city' => $this->city,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'type' => $this->type,
            'category' => $this->category,
            'manager' => $this->manager,
            'managerPhone' => $this->manager_phone,
            'startsOn' => $this->starts_on->format('Y-m-d'),
            'endsOn' => $this->ends_on->format('Y-m-d'),
            'plannedDays' => $this->planned_days,
            'executedDays' => $this->executedDays(),
            'progress' => $this->progress,
            'required' => $this->required_workers,
            'assigned' => $this->assigned_workers,
            'present' => $this->present_workers,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label(), 'tone' => $this->priority->tone()],
            'description' => $this->description,
            'workingHours' => $this->working_hours,
            'workingDays' => $this->working_days,
            'instructions' => $this->instructions,
            'accessConditions' => $this->access_conditions,
            'safetyEquipment' => $this->safety_equipment,
            'budget' => $this->budget,
            'engaged' => $this->engaged_amount,
            'paid' => $this->paid_amount,
            'correctionRequest' => $this->correction_request,
            'submittedOn' => $this->submitted_on->format('Y-m-d'),
        ];
    }
}
