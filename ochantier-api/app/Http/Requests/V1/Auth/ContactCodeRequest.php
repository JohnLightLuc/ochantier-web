<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Code reçu par téléphone ou e-mail, vérifié sans ouvrir de session (écran « Vérification » du mot de passe oublié).
 */
class ContactCodeRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->contactRules(), 'code' => $this->codeRules()];
    }
}
