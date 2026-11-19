<?php

namespace App\Enums;

/**
 * Statut d’un versement à un professionnel O’CHANTIER PRO.
 */
enum ContractorPayoutStatus: string
{
    case APayer = 'a_payer';
    case EnTraitement = 'en_traitement';
    case Paye = 'paye';
    case Rejete = 'rejete';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::APayer => 'À valider',
            self::EnTraitement => 'En cours d’envoi',
            self::Paye => 'Payé',
            self::Rejete => 'Rejeté',
            self::Annule => 'Annulé',
        };
    }
}
