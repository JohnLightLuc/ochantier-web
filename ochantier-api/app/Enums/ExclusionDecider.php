<?php

namespace App\Enums;

/**
 * Valeurs de `decided_by_type` pour une exclusion de matching.
 */
enum ExclusionDecider: string
{
    case Systeme = 'systeme';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Systeme => 'Système',
            self::Admin => 'Administrateur',
        };
    }
}
