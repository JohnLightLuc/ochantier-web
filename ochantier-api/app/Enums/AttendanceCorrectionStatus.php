<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une correction de pointage.
 */
enum AttendanceCorrectionStatus: string
{
    case Demandee = 'demandee';
    case Approuvee = 'approuvee';
    case Refusee = 'refusee';

    public function label(): string
    {
        return match ($this) {
            self::Demandee => 'Demandée',
            self::Approuvee => 'Approuvée',
            self::Refusee => 'Refusée',
        };
    }
}
