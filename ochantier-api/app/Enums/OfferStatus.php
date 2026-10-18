<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une offre.
 */
enum OfferStatus: string
{
    case Brouillon = 'brouillon';
    case Soumise = 'soumise';
    case EnControle = 'en_controle';
    case ACorriger = 'a_corriger';
    case Publiee = 'publiee';
    case Suspendue = 'suspendue';
    case Pourvue = 'pourvue';
    case Cloturee = 'cloturee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise',
            self::EnControle => 'En contrôle',
            self::ACorriger => 'À corriger',
            self::Publiee => 'Publiée',
            self::Suspendue => 'Suspendue',
            self::Pourvue => 'Pourvue',
            self::Cloturee => 'Clôturée',
            self::Annulee => 'Annulée',
        };
    }
}
