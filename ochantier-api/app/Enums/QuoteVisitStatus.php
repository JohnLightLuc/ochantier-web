<?php

namespace App\Enums;

/**
 * Valeurs de `visit_status` : mission de visite d’une proposition de devis commandée.
 */
enum QuoteVisitStatus: string
{
    case Planifiee = 'planifiee';
    case ReportPropose = 'report_propose';
    case Realisee = 'realisee';

    public function label(): string
    {
        return match ($this) {
            self::Planifiee => 'Planifiée',
            self::ReportPropose => 'Report proposé',
            self::Realisee => 'Réalisée',
        };
    }
}
