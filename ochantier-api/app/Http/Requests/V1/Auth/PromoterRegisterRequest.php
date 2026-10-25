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
            'referral_code' => ['nullable', 'string', 'max:20', Rule::exists('promoter_profiles', 'referral_code')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'company_name.required_if' => 'Indiquez le nom de votre entreprise.', 'referral_code.exists' => 'Code parrain inconnu.', 'referral_code.max' => 'Code parrain inconnu.'];
    }
}
