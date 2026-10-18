<?php

namespace App\Enums;

/**
 * Valeurs de `cap_period` pour la table `commercial_earning_rules`.
 */
enum EarningCapPeriod: string
{
    case Semaine = 'semaine';
    case Mois = 'mois';

    public function label(): string
    {
        return match ($this) {
            self::Semaine => 'Semaine',
            self::Mois => 'Mois',
        };
    }
}
