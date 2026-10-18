<?php

namespace App\Enums;

/**
 * Valeurs de `kind` pour un instantané tarifaire.
 */
enum PricingSnapshotKind: string
{
    case Simulation = 'simulation';
    case Devis = 'devis';
    case Confirme = 'confirme';

    public function label(): string
    {
        return match ($this) {
            self::Simulation => 'Simulation',
            self::Devis => 'Devis',
            self::Confirme => 'Confirmé',
        };
    }
}
