<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\CommercialProfile;
use App\Support\Admin\CommercialStatistics;
use Illuminate\Http\Request;

/**
 * Fiche d’un commercial : compte, contrat, indicateurs, évolution sur six mois et répartition par métier.
 *
 * @mixin CommercialProfile
 */
class CommercialDetailResource extends CommercialListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->account_status;
        $user = $this->user;
        $statistics = app(CommercialStatistics::class);

        return [
            ...parent::toArray($request),
            'secondary_phone' => $this->secondary_phone,
            'address' => $this->address,
            'is_supervisor' => $this->is_supervisor,
            'verification_status' => $this->status($this->verification_status),
            'ended_at' => $this->date($this->ended_at),
            'created_by' => $this->createdByAdmin ? trim($this->createdByAdmin->first_name.' '.$this->createdByAdmin->last_name) : null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'suspension' => $status === 'suspendu' ? [
                'reason' => $user->suspended_reason,
                'at' => $user->suspended_at?->toIso8601String(),
                'by' => $user->suspendedByAdmin ? trim($user->suspendedByAdmin->first_name.' '.$user->suspendedByAdmin->last_name) : null,
            ] : null,
            'termination_reason' => $status === 'termine' ? $statistics->terminationReason($this->resource) : null,
            'ranking' => $statistics->ranking($this->resource, $status),
            'months' => $statistics->months($this->resource),
            'trades' => $statistics->trades($this->resource),
            'can' => [
                'suspend' => $status === 'actif',
                'reactivate' => in_array($status, ['suspendu', 'termine'], true),
                'terminate' => $status !== 'termine',
            ],
        ];
    }
}
