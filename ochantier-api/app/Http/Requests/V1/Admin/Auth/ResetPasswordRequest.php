<?php

namespace App\Http\Requests\V1\Admin\Auth;

/**
 * Nouveau mot de passe choisi depuis le lien de réinitialisation.
 */
class ResetPasswordRequest extends AdminAuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailRules(),
            'token' => ['required', 'string', 'size:64'],
            'password' => $this->newPasswordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'token.required' => 'Ce lien est incomplet.', 'token.size' => 'Ce lien est incomplet.'];
    }
}
