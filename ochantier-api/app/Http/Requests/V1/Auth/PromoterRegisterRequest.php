<?php

namespace App\Http\Requests\V1\Auth;

use App\Enums\PromoterProfileKind;
use Illuminate\Validation\Rule;

/**
 * Inscription d’un promoteur : profil, téléphone ou e-mail, acceptation des conditions.
 */
class PromoterRegisterRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'profile_kind' => ['required', Rule::enum(PromoterProfileKind::class)],
            'company_name' => ['required_if:profile_kind,entreprise', 'nullable', 'string', 'max:200'],
            ...$this->contactRules(),
            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'company_name.required_if' => 'Indiquez le nom de votre entreprise.'];
    }
}
