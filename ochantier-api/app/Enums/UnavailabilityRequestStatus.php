<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une indisponibilité.
 */
enum UnavailabilityRequestStatus: string
{
    case Declaree = 'declaree';
    case EnAnalyse = 'en_analyse';
    case RemplacementRecherche = 'remplacement_recherche';
    case RemplacantPropose = 'remplacant_propose';
    case Validee = 'validee';
    case Refusee = 'refusee';
    case Cloturee = 'cloturee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Declaree => 'Déclarée',
            self::EnAnalyse => 'En analyse',
            self::RemplacementRecherche => 'Remplacement recherché',
            self::RemplacantPropose => 'Remplaçant proposé',
            self::Validee => 'Validée',
            self::Refusee => 'Refusée',
            self::Cloturee => 'Clôturée',
            self::Annulee => 'Annulée',
        };
    }
}
