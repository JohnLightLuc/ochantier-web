<?php

namespace App\Enums;

/**
 * Valeurs de `platform` pour la table `app_versions`.
 */
enum AppPlatform: string
{
    case Android = 'android';
    case Ios = 'ios';

    public function label(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
        };
    }
}
