<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un reversement d’ouvrier.
 */
enum WorkerPayoutStatus: string
{
    case ACalculer = 'a_calculer';
    case Provisionne = 'provisionne';
    case Autorise = 'autorise';
    case Envoye = 'envoye';
    case Confirme = 'confirme';
    case Rejete = 'rejete';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::ACalculer => 'À calculer',
            self::Provisionne => 'Provisionné',
            self::Autorise => 'Autorisé',
            self::Envoye => 'Envoyé',
            self::Confirme => 'Confirmé',
            self::Rejete => 'Rejeté',
            self::Annule => 'Annulé',
        };
    }
}
