<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour l’offre d’un technicien.
 */
enum QuoteBidStatus: string
{
    case Proposee = 'proposee';
    case Choisie = 'choisie';
    case NonRetenue = 'non_retenue';

    public function label(): string
    {
        return match ($this) {
            self::Proposee => 'Proposée',
            self::Choisie => 'Choisie',
            self::NonRetenue => 'Non retenue',
        };
    }
}
