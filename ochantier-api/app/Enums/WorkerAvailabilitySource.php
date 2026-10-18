<?php

namespace App\Enums;

/**
 * Valeurs de `source` pour une disponibilité d’ouvrier.
 */
enum WorkerAvailabilitySource: string
{
    case Commercial = 'commercial';
    case BackOffice = 'back_office';
    case Ouvrier = 'ouvrier';
    case Systeme = 'systeme';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Commercial',
            self::BackOffice => 'Back Office',
            self::Ouvrier => 'Ouvrier',
            self::Systeme => 'Système',
        };
    }
}
