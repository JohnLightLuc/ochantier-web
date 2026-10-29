<?php

namespace App\Http\Requests\V1\Admin\Auth;

/**
 * Vérification en deux étapes : code reçu par e-mail.
 */
class VerifyLoginRequest extends AdminAuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailRules(),
            'code' => $this->codeRules(),
            ...$this->deviceRules(),
        ];
    }
}
