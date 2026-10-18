<?php

namespace App\Http\Resources\V1;

use App\Models\AuthSession;
use Illuminate\Http\Request;

/**
 * Appareil connecté au compte (écran « Sécurité et accès »).
 *
 * @mixin AuthSession
 */
class AuthSessionResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'current' => $this->id === $request->attributes->get('auth_session')?->id,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
