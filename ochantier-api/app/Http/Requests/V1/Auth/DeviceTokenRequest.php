<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Jeton de notification push de l’appareil.
 */
class DeviceTokenRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'push_token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', 'in:android,ios,web'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
