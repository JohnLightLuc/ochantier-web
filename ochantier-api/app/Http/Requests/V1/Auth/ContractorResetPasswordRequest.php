<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Nouveau mot de passe d’un entrepreneur, choisi après vérification du code reçu par SMS ou e-mail.
 */
class ContractorResetPasswordRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->contactRules(), 'code' => $this->codeRules(), 'password' => $this->newPasswordRules()];
    }
}
