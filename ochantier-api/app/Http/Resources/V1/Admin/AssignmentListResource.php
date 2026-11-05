<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\ReplacementStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Assignment;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des affectations du Back Office.
 *
 * @mixin Assignment
 */
class AssignmentListResource extends Resource
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
        $replacement = ReplacementStatus::tryFrom((string) $this->open_replacement_status);

        return [
            'id' => $this->id,
            'status' => $this->status($this->status),
            'worker' => ['id' => $worker->id, 'reference' => $worker->reference, 'full_name' => trim($worker->first_name.' '.$worker->last_name), 'phone' => PhoneNumber::of($worker)?->formatted()],
            'site' => ['id' => $site->id, 'reference' => $site->reference, 'label' => $site->label, 'commune' => $site->commune?->name],
            'promoter' => ['id' => $site->promoter_profile_id, 'name' => $organization ? ($organization->trade_name ?: $organization->legal_name) : trim($user?->first_name.' '.$user?->last_name)],
            'mission' => ['id' => $this->mission_id, 'reference' => $this->mission->reference],
            'offer_id' => $this->need->offer_id,
            'trade' => $this->need->trade?->label,
            'role' => $this->role_label,
            'is_team_lead' => (bool) $this->is_team_lead,
            'start_date' => $this->date($this->planned_start_date),
            'end_date' => $this->date($this->planned_end_date),
            'promoter_daily_rate' => $this->promoter_daily_rate,
            'worker_net_daily_rate' => $this->worker_net_daily_rate,
            'replacement' => $this->open_replacement_id ? ['id' => $this->open_replacement_id, 'status' => $this->status($replacement)] : null,
        ];
    }
}
