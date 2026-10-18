<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un gain de commercial.
 */
enum CommercialEarningStatus: string
{
    case Estime = 'estime';
    case EnAttente = 'en_attente';
    case Acquis = 'acquis';
    case Approuve = 'approuve';
    case Paye = 'paye';
    case Rejete = 'rejete';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Estime => 'Estimé',
            self::EnAttente => 'En attente',
            self::Acquis => 'Acquis',
            self::Approuve => 'Approuvé',
            self::Paye => 'Payé',
            self::Rejete => 'Rejeté',
            self::Annule => 'Annulé',
        };
    }
}
