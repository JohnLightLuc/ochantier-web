<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\PromoterProfileKind;
use App\Enums\UserStatus;
use App\Http\Resources\V1\Resource;
use App\Models\PromoterProfile;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Ligne de la liste des promoteurs du Back Office.
 *
 * @mixin PromoterProfile
 */
class PromoterListResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $organization = $this->profile_kind === PromoterProfileKind::Entreprise ? $user->ownedOrganizations->first() : null;

        return [
            'id' => $this->id,
            'full_name' => trim($user->first_name.' '.$user->last_name) ?: null,
            'phone' => PhoneNumber::of($user)?->toArray()['phone'],
            'email' => $user->email,
            'kind' => $this->status($this->profile_kind),
            'company' => $organization?->only(['id', 'legal_name', 'trade_name', 'rccm']),
            'commune' => $this->commune?->only(['id', 'name']),
            'status' => self::displayStatus($this->resource),
            'verification_status' => $this->status($this->verification_status),
            'account_status' => $this->status($user->status),
            'sites_count' => (int) ($this->sites_count ?? 0),
            'expired_documents_count' => (int) ($this->expired_documents_count ?? 0),
            'open_disputes_count' => (int) ($this->open_disputes_count ?? 0),
            'registered_at' => $this->created_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
        ];
    }

    /**
     * Statut affiché : « Suspendu » pour un compte suspendu, sinon le statut de vérification du dossier.
     *
     * @return array{value: string, label: string}
     */
    public static function displayStatus(PromoterProfile $profile): array
    {
        return $profile->user->status === UserStatus::Suspendu
            ? ['value' => 'suspendu', 'label' => 'Suspendu']
            : ['value' => $profile->verification_status->value, 'label' => $profile->verification_status->label()];
    }
}
