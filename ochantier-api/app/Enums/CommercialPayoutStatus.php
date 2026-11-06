<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un paiement de commercial.
 */
enum CommercialPayoutStatus: string
{
    case APayer = 'a_payer';
    case EnTraitement = 'en_traitement';
    case Paye = 'paye';
    case Rejete = 'rejete';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::APayer => 'À payer',
            self::EnTraitement => 'En traitement',
            self::Paye => 'Payé',
            self::Rejete => 'Rejeté',
            self::Annule => 'Annulé',
        };
    }
}
