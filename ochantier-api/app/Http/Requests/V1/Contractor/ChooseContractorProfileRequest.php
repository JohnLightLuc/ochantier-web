<?php

namespace App\Http\Requests\V1\Contractor;

use App\Enums\ContractorKind;
use Illuminate\Validation\Rule;

/**
 * « Quel est votre profil ? » : entreprise (vérification KYB) ou indépendant (vérification KYC), avec, si
 * l’application les envoie déjà, les premiers champs du dossier.
 */
class ChooseContractorProfileRequest extends UpdateContractorProfileRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'kind' => ['required', Rule::enum(ContractorKind::class)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'kind.required' => 'Choisissez votre profil : entreprise ou indépendant.',
            'kind.enum' => 'Choisissez votre profil : entreprise ou indépendant.',
        ];
    }
}
