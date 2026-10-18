<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un rapport planifié.
 */
enum ReportScheduleStatus: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
        };
    }
}
