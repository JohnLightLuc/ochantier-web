<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\CommercialProfile;
use App\Models\CommercialZone;
use App\Support\Admin\CommercialDirectory;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des commerciaux du Back Office : compte, zones et indicateurs du mois.
 *
 * @mixin CommercialProfile
 */
class CommercialListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $supervisor = $this->supervisor?->user;
        $target = $this->objective_target !== null ? (int) $this->objective_target : null;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => trim($user->first_name.' '.$user->last_name),
            'phone' => PhoneNumber::of($user)?->formatted(),
            'email' => $user->email,
            'status' => ['value' => $this->account_status, 'label' => CommercialDirectory::STATUSES[$this->account_status]],
            'zones' => $this->commercialZones->map(fn (CommercialZone $zone) => ['id' => $zone->commune_id, 'name' => $zone->commune->name, 'is_primary' => $zone->is_primary])->values()->all(),
            'supervisor' => $supervisor ? ['id' => $this->supervisor_id, 'full_name' => trim($supervisor->first_name.' '.$supervisor->last_name)] : null,
            'workers' => [
                'total' => (int) $this->total_workers,
                'month_registered' => (int) $this->month_registered,
                'month_validated' => (int) $this->month_validated,
            ],
            'validation_rate' => $this->validation_rate !== null ? (int) $this->validation_rate : null,
            'objective' => $target ? ['target' => $target, 'achieved' => (int) $this->objective_achieved, 'percent' => (int) $this->objective_percent] : null,
            'commissions' => ['month' => (int) $this->month_commissions, 'unpaid' => (int) $this->unpaid_commissions],
            'started_at' => $this->date($this->started_at),
            'last_activity_at' => $this->last_activity_at ? Carbon::parse($this->last_activity_at)->toIso8601String() : null,
        ];
    }
}
