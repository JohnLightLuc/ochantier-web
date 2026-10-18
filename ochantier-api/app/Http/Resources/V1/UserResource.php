<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;

/**
 * Compte connecté : identité, coordonnées et profil propre à l’application (promoteur ou commercial).
 *
 * @mixin User
 */
class UserResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->status($this->user_type),
            'status' => $this->status($this->status),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone ? Phone::format($this->phone) : null,
            'phone_verified' => $this->phone_verified_at !== null,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'promoter' => $this->whenLoaded('promoterProfile', fn () => $this->promoterProfile ? [
                'id' => $this->promoterProfile->id,
                'profile_kind' => $this->status($this->promoterProfile->profile_kind),
                'verification_status' => $this->status($this->promoterProfile->verification_status),
                'organization' => $this->ownedOrganizations->first()?->only(['id', 'legal_name', 'trade_name']),
            ] : null),
            'commercial' => $this->whenLoaded('commercialProfile', fn () => $this->commercialProfile ? [
                'id' => $this->commercialProfile->id,
                'employee_number' => $this->commercialProfile->employee_number,
                'status' => $this->status($this->commercialProfile->status),
                'verification_status' => $this->status($this->commercialProfile->verification_status),
            ] : null),
        ];
    }
}
