<?php

namespace App\Enums;

/**
 * Valeurs de `sanction_type` pour une sanction d’ouvrier.
 */
enum WorkerSanctionType: string
{
    case Avertissement = 'avertissement';
    case Suspension = 'suspension';
    case Exclusion = 'exclusion';

    public function label(): string
    {
        return match ($this) {
            self::Avertissement => 'Avertissement',
            self::Suspension => 'Suspension',
            self::Exclusion => 'Exclusion',
        };
    }
}
