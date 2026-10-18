<?php

namespace App\Enums;

/**
 * Valeurs de `event_type` pour la table `commercial_earning_rules`.
 */
enum CommercialEarningEvent: string
{
    case OuvrierValide = 'ouvrier_valide';

    public function label(): string
    {
        return match ($this) {
            self::OuvrierValide => 'Ouvrier validé',
        };
    }
}
