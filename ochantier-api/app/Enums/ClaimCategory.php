<?php

namespace App\Enums;

/**
 * Valeurs de `category` pour une réclamation.
 */
enum ClaimCategory: string
{
    case Paiement = 'paiement';
    case Pointage = 'pointage';
    case Prestation = 'prestation';
    case Comportement = 'comportement';
    case Securite = 'securite';
    case Materiel = 'materiel';
    case Remplacement = 'remplacement';
    case Avis = 'avis';
    case GainCommercial = 'gain_commercial';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Paiement => 'Paiement',
            self::Pointage => 'Pointage',
            self::Prestation => 'Qualité de la prestation',
            self::Comportement => 'Comportement',
            self::Securite => 'Sécurité',
            self::Materiel => 'Matériel',
            self::Remplacement => 'Remplacement',
            self::Avis => 'Avis contesté',
            self::GainCommercial => 'Gain commercial',
            self::Autre => 'Autre',
        };
    }
}
