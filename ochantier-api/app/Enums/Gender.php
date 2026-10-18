<?php

namespace App\Enums;

/**
 * Valeurs de `gender` pour un ouvrier.
 */
enum Gender: string
{
    case M = 'M';
    case F = 'F';

    public function label(): string
    {
        return match ($this) {
            self::M => 'Homme',
            self::F => 'Femme',
        };
    }
}
