<?php

namespace App\Enums;

/**
 * Valeurs de `calculation` pour la table `commercial_earning_rules`.
 */
enum EarningCalculation: string
{
    case MontantFixe = 'montant_fixe';
    case Pourcentage = 'pourcentage';

    public function label(): string
    {
        return match ($this) {
            self::MontantFixe => 'Montant fixe',
            self::Pourcentage => 'Pourcentage',
        };
    }
}
