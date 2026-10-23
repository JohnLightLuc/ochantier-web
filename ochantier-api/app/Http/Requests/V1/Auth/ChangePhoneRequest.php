<?php

namespace App\Http\Requests\V1\Auth;

use App\Support\OneTimePassword;

/**
 * Nouveau numéro à confirmer par un code SMS.
 */
class ChangePhoneRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->phoneRules(['required'], uniqueFor: $this->user()->user_type, ignoreId: $this->user()->getKey()),
            'code' => [$this->routeIs('*.verify') ? 'required' : 'prohibited', 'digits:'.OneTimePassword::length()],
        ];
    }
}
