<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Mot de passe choisi depuis le lien reçu par un commercial.
 */
class CommercialActivationRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:128'], 'password' => $this->newPasswordRules()];
    }
}
