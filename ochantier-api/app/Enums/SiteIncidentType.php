<?php

namespace App\Enums;

/**
 * Valeurs de `incident_type` pour un incident de chantier.
 */
enum SiteIncidentType: string
{
    case Accident = 'accident';
    case Securite = 'securite';
    case Materiel = 'materiel';
    case Retard = 'retard';
    case Conflit = 'conflit';
    case Qualite = 'qualite';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Accident => 'Accident',
            self::Securite => 'Sécurité',
            self::Materiel => 'Matériel',
            self::Retard => 'Retard',
            self::Conflit => 'Conflit',
            self::Qualite => 'Qualité',
            self::Autre => 'Autre',
        };
    }
}
