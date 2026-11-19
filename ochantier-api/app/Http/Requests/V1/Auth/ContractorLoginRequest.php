<?php

namespace App\Http\Requests\V1\Auth;

use App\Support\Auth\DeviceContext;

/**
 * Connexion d’un entrepreneur ou technicien : téléphone ou e-mail et mot de passe, sur cet appareil.
 */
class ContractorLoginRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->contactRules(), 'password' => ['required', 'string'], ...DeviceContext::rules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'password.required' => 'Saisissez votre mot de passe.'];
    }
}
