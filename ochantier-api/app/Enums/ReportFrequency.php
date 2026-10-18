<?php

namespace App\Enums;

/**
 * Valeurs de `frequency` pour un rapport planifié.
 */
enum ReportFrequency: string
{
    case Quotidien = 'quotidien';
    case Hebdomadaire = 'hebdomadaire';
    case Mensuel = 'mensuel';

    public function label(): string
    {
        return match ($this) {
            self::Quotidien => 'Quotidien',
            self::Hebdomadaire => 'Hebdomadaire',
            self::Mensuel => 'Mensuel',
        };
    }
}
