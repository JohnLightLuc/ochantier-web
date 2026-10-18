<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une affectation.
 */
enum AssignmentStatus: string
{
    case Confirmee = 'confirmee';
    case Active = 'active';
    case Terminee = 'terminee';
    case Remplacee = 'remplacee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Confirmee => 'Confirmée',
            self::Active => 'Active',
            self::Terminee => 'Terminée',
            self::Remplacee => 'Remplacée',
            self::Annulee => 'Annulée',
        };
    }
}
