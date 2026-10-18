<?php

namespace App\Enums;

/**
 * Valeurs de `billing_period` pour la table `subscription_plans`.
 */
enum BillingPeriod: string
{
    case Mois = 'mois';
    case Trimestre = 'trimestre';
    case Annee = 'annee';

    public function label(): string
    {
        return match ($this) {
            self::Mois => 'Mois',
            self::Trimestre => 'Trimestre',
            self::Annee => 'Année',
        };
    }
}
