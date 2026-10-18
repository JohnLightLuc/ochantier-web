<?php

namespace App\Http\Requests\V1\Auth;

use App\Http\Requests\V1\PhoneRule;

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
            'phone' => ['required', 'string', new PhoneRule($this->user()->user_type, $this->user()->getKey())],
            'code' => [$this->routeIs('*.verify') ? 'required' : 'prohibited', 'digits:6'],
        ];
    }
}
