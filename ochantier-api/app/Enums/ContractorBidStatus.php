<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour l’offre d’un entrepreneur.
 */
enum ContractorBidStatus: string
{
    case Proposee = 'proposee';
    case Choisie = 'choisie';
    case Retenue = 'retenue';
    case NonRetenue = 'non_retenue';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Choisie => 'Choisie · acompte à payer',
            self::Retenue => 'Retenue',
            self::NonRetenue => 'Non retenue',
            self::Annulee => 'Annulée',
        };
    }
}
