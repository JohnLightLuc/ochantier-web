<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Nouveau mot de passe choisi après vérification du code reçu par SMS.
 */
class ResetPasswordRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->phoneRules(),
            'code' => $this->codeRules(),
            'password' => $this->newPasswordRules(),
        ];
    }
}
