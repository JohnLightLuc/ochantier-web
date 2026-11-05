<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\Site;
use App\Support\Admin\SiteDirectory;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des chantiers du Back Office.
 *
 * @mixin Site
 */
class SiteListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->promoterProfile?->user;
        $organization = $this->organization;
        $required = (int) $this->required_workers;
        $assigned = (int) $this->assigned_workers;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'label' => $this->label,
            'promoter' => [
                'id' => $this->promoter_profile_id,
                'name' => $organization ? ($organization->trade_name ?: $organization->legal_name) : trim($user?->first_name.' '.$user?->last_name),
            ],
            'commune' => $this->commune?->only(['id', 'name']),
            'construction_type' => $this->constructionType?->only(['id', 'label']),
            'work_type' => $this->status($this->work_type),
            'priority' => $this->status($this->priority),
            'status' => $this->status($this->status),
            'planned_start_date' => $this->date($this->planned_start_date),
            'planned_end_date' => $this->date($this->planned_end_date),
            'staffing' => ['required' => $required, 'assigned' => $assigned],
            'is_late' => in_array($this->status, SiteDirectory::ACTIVE, true) && $this->planned_end_date?->lt(today()),
            'open_incidents_count' => (int) $this->open_incidents_count,
            'pending_days_count' => (int) $this->pending_days_count,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
