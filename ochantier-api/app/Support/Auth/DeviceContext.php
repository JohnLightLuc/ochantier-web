<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * Appareil et application à l’origine d’une connexion, enregistrés avec la session.
 */
final readonly class DeviceContext
{
    public function __construct(
        public string $deviceName,
        public ?string $deviceId,
        public ?string $platform,
        public ?string $appVersion,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            deviceName: (string) $request->input('device_name', 'Appareil inconnu'),
            deviceId: $request->input('device_id'),
            platform: $request->input('platform'),
            appVersion: $request->input('app_version'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    /**
     * Règles de validation communes aux écrans qui ouvrent une session.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'in:android,ios,web'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
