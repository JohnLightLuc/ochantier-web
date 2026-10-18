<?php

namespace App\Enums;

/**
 * Disponibilité d’un ouvrier.
 */
enum AvailabilityStatus: string
{
    case Disponible = 'disponible';
    case Occupe = 'occupe';
    case Indisponible = 'indisponible';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Occupe => 'Occupé',
            self::Indisponible => 'Indisponible',
        };
    }
}
