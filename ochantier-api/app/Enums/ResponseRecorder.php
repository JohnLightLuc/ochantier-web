<?php

namespace App\Enums;

/**
 * Valeurs de `recorded_by_type` pour une réponse d’ouvrier.
 */
enum ResponseRecorder: string
{
    case Commercial = 'commercial';
    case Admin = 'admin';
    case Worker = 'worker';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Commercial',
            self::Admin => 'Administrateur',
            self::Worker => 'Ouvrier',
        };
    }
}
