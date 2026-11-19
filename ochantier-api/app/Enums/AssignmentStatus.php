<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une affectation.
 */
enum AssignmentStatus: string
{
    case EnAttente = 'en_attente';
    case Confirmee = 'confirmee';
    case Active = 'active';
    case Terminee = 'terminee';
    case Remplacee = 'remplacee';
    case Annulee = 'annulee';
    case Refusee = 'refusee';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente d’accord',
            self::Confirmee => 'Confirmée',
            self::Active => 'Active',
            self::Terminee => 'Terminée',
            self::Remplacee => 'Remplacée',
            self::Annulee => 'Annulée',
            self::Refusee => 'Refusée par l’ouvrier',
        };
    }
}
