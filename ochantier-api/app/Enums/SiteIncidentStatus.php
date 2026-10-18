<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un incident de chantier.
 */
enum SiteIncidentStatus: string
{
    case Ouvert = 'ouvert';
    case EnTraitement = 'en_traitement';
    case Resolu = 'resolu';
    case Clos = 'clos';

    public function label(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::EnTraitement => 'En traitement',
            self::Resolu => 'Résolu',
            self::Clos => 'Clos',
        };
    }
}
