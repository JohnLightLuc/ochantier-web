<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une réservation d’ouvrier.
 */
enum WorkerReservationStatus: string
{
    case Active = 'active';
    case Convertie = 'convertie';
    case Expiree = 'expiree';
    case Liberee = 'liberee';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Convertie => 'Convertie',
            self::Expiree => 'Expirée',
            self::Liberee => 'Libérée',
        };
    }
}
