<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un jalon d’offre.
 */
enum OfferMilestoneStatus: string
{
    case Prevu = 'prevu';
    case Atteint = 'atteint';
    case Valide = 'valide';
    case Paye = 'paye';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Prevu => 'Prévu',
            self::Atteint => 'Atteint',
            self::Valide => 'Validé',
            self::Paye => 'Payé',
            self::Annule => 'Annulé',
        };
    }
}
