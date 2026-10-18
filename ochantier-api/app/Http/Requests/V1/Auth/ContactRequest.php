<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Demande d’un code de connexion par téléphone ou e-mail.
 */
class ContactRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->contactRules();
    }
}
