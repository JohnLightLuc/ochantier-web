<?php

namespace App\Http\Resources;

use App\Models\SalesAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commercial : identité, poste, statuts, rémunération et accès.
 *
 * @mixin SalesAgent
 */
class SalesAgentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pay = $this->pay;
        $access = $this->access;
        $enum = fn ($e): array => ['value' => $e->value, 'label' => $e->label(), 'tone' => $e->tone()];

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'code' => $this->code,
            'name' => $this->name,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'gender' => $this->gender,
            'birthDate' => $this->birth_date?->format('Y-m-d'),
            'nationality' => $this->nationality,
            'phone' => $this->phone,
            'phoneSecondary' => $this->phone_secondary,
            'email' => $this->email,
            'address' => $this->address,
            'commune' => $this->commune,
            'city' => $this->city,
            'emergencyContact' => $this->emergency_contact,
            'emergencyPhone' => $this->emergency_phone,
            'type' => $this->type,
            'jobTitle' => $this->job_title,
            'agency' => $this->agency,
            'agencyLabel' => $this->agencyLabel(),
            'zone' => $this->zone,
            'secondaryZones' => $this->secondary_zones,
            'communes' => $this->communes,
            'priorityTrades' => $this->priority_trades,
            'specialties' => $this->specialties,
            'supervisor' => $this->supervisor,
            'startedAt' => $this->started_at->format('Y-m-d'),
            'contract' => $this->contract,
            'weeklyTarget' => $this->weekly_target,
            'monthlyTarget' => $this->monthly_target,
            'internalNote' => $this->internal_note,
            'idType' => $this->id_type,
            'idNumber' => $this->id_number,
            'idIssuedAt' => $this->id_issued_at?->format('Y-m-d'),
            'idExpiresAt' => $this->id_expires_at?->format('Y-m-d'),
            'status' => $enum($this->status),
            'invitation' => $enum($this->invitation_status),
            'availability' => $enum($this->availability),
            'suspensionReason' => $this->suspension_reason,
            'lastActivityOn' => $this->last_activity_on?->format('Y-m-d'),
            'recruitedTotal' => $this->recruited_total,
            'isArchived' => $this->isArchived(),
            'isPreActivation' => $this->status->isPreActivation(),
            'pay' => [
                'mode' => $pay['mode'],
                'fixed' => $pay['fixed'],
                'perRegistered' => $pay['per_registered'],
                'perValidated' => $pay['per_validated'],
                'bonusObjective' => $pay['bonus_objective'],
                'bonusCotm' => $pay['bonus_cotm'],
                'calcFrequency' => $pay['calc_frequency'],
                'payFrequency' => $pay['pay_frequency'],
                'effectiveFrom' => $pay['effective_from'],
                'cap' => $pay['cap'],
                'eligibility' => $pay['eligibility'],
                'method' => $pay['method'],
                'note' => $pay['note'] ?? null,
            ],
            'access' => [
                'role' => $access['role'],
                'level' => $access['level'],
                'modules' => $access['modules'],
                'dataZones' => $access['data_zones'],
                'permissions' => $access['permissions'],
                'twoFactor' => $access['two_factor'],
                'duration' => $access['duration'],
                'sentAt' => $access['sent_at'],
                'viewedAt' => $access['viewed_at'],
                'activatedAt' => $access['activated_at'],
                'expiresAt' => $access['expires_at'],
                'lastLogin' => $access['last_login'],
                'passwordChangedAt' => $access['password_changed_at'],
                'failedAttempts' => $access['failed_attempts'],
                'sessions' => $access['sessions'],
            ],
        ];
    }
}
