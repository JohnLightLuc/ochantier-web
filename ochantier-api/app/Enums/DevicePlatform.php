<?php

namespace App\Enums;

/**
 * Valeurs de `platform` pour la table `device_tokens`.
 */
enum DevicePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
            self::Web => 'Web',
        };
    }
}
