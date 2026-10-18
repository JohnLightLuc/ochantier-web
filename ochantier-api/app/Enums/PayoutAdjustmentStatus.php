<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un ajustement de reversement.
 */
enum PayoutAdjustmentStatus: string
{
    case Proposee = 'proposee';
    case Approuvee = 'approuvee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Approuvee => 'Approuvée',
            self::Refusee => 'Refusée',
            self::Annulee => 'Annulée',
        };
    }
}
