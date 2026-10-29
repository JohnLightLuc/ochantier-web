<?php

namespace App\Http\Requests\V1\Admin\Auth;

/**
 * E-mail seul : renvoi du code de connexion, mot de passe oublié.
 */
class EmailRequest extends AdminAuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['email' => $this->emailRules()];
    }
}
