<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour la proposition de devis d’un entrepreneur.
 */
enum QuoteBidStatus: string
{
    case Proposee = 'proposee';
    case Choisie = 'choisie';
    case NonRetenue = 'non_retenue';
    case Retiree = 'retiree';

    public function label(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Choisie => 'Choisie',
            self::NonRetenue => 'Non retenue',
            self::Retiree => 'Retirée',
        };
    }
}
