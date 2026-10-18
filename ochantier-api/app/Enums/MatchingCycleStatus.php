<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un cycle de matching.
 */
enum MatchingCycleStatus: string
{
    case EnCours = 'en_cours';
    case Termine = 'termine';
    case Echoue = 'echoue';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Termine => 'Terminé',
            self::Echoue => 'Échoué',
            self::Annule => 'Annulé',
        };
    }
}
