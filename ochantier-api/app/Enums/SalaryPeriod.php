<?php

namespace App\Enums;

/**
 * Période de référence d’un salaire contractuel.
 */
enum SalaryPeriod: string
{
    case Jour = 'jour';
    case Semaine = 'semaine';
    case Mois = 'mois';
    case Forfait = 'forfait';

    public function label(): string
    {
        return match ($this) {
            self::Jour => 'Jour',
            self::Semaine => 'Semaine',
            self::Mois => 'Mois',
            self::Forfait => 'Forfait',
        };
    }
}
