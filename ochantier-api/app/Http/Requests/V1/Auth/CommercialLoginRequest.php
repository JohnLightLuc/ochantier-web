<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Connexion d’un commercial : numéro et mot de passe, avant le code SMS.
 */
class CommercialLoginRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->phoneRules(),
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'password.required' => 'Saisissez votre mot de passe.'];
    }
}
