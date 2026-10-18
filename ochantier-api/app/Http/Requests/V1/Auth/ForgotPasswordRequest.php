<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Mot de passe oublié : numéro du compte à qui envoyer le code.
 */
class ForgotPasswordRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->phoneRules();
    }
}
