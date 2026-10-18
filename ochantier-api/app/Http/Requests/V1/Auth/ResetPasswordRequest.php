<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\V1\PhoneRule;

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
            'phone' => ['required', 'string', new PhoneRule],
            'code' => $this->codeRules(),
            'password' => $this->newPasswordRules(),
        ];
    }
}
