<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\V1\PhoneRule;
use App\Support\Auth\DeviceContext;

/**
 * Numéro et code SMS : second facteur de connexion du commercial.
 */
class PhoneCodeRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneRule],
            'code' => $this->codeRules(),
            ...DeviceContext::rules(),
        ];
    }
}
