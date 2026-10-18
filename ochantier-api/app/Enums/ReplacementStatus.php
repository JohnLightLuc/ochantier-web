<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un remplacement.
 */
enum ReplacementStatus: string
{
    case Signale = 'signale';
    case Recherche = 'recherche';
    case Propose = 'propose';
    case Valide = 'valide';
    case PriseDePoste = 'prise_de_poste';
    case Termine = 'termine';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Signale => 'Signalé',
            self::Recherche => 'Recherche',
            self::Propose => 'Proposé',
            self::Valide => 'Validé',
            self::PriseDePoste => 'Prise de poste',
            self::Termine => 'Terminé',
            self::Annule => 'Annulé',
        };
    }
}
