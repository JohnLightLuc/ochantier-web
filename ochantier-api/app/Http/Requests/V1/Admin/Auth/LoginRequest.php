<?php

namespace App\Http\Requests\V1\Admin\Auth;

/**
 * Connexion : e-mail professionnel et mot de passe.
 */
class LoginRequest extends AdminAuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailRules(),
            'password' => ['required', 'string', 'max:128'],
            ...$this->deviceRules(),
        ];
    }
}
