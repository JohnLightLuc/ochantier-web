<?php

namespace App\Http\Requests\V1\Auth;

use App\Support\Auth\DeviceContext;

/**
 * Code reçu par téléphone ou e-mail, échangé contre une session sur cet appareil.
 */
class VerifyContactRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->contactRules(), 'code' => $this->codeRules(), ...DeviceContext::rules()];
    }
}
