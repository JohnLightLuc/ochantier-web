<?php

namespace App\Enums;

/**
 * Proposition d’équipe présentée au promoteur.
 */
enum ProposalStatus: string
{
    case AValider = 'a_valider';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';
    case Expiree = 'expiree';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::AValider => 'À valider',
            self::Acceptee => 'Acceptée',
            self::Refusee => 'Refusée',
            self::Expiree => 'Expirée',
            self::Annulee => 'Annulée',
        };
    }
}
