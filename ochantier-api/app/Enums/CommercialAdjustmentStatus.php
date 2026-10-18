<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une prime ou retenue de commercial.
 */
enum CommercialAdjustmentStatus: string
{
    case Proposee = 'proposee';
    case Approuvee = 'approuvee';
    case Refusee = 'refusee';
    case Payee = 'payee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Approuvee => 'Approuvée',
            self::Refusee => 'Refusée',
            self::Payee => 'Payée',
            self::Annulee => 'Annulée',
        };
    }
}
