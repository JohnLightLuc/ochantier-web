<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un chantier.
 */
enum SiteStatus: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case Recherche = 'recherche';
    case Paiement = 'paiement';
    case Confirme = 'confirme';
    case EnCours = 'en_cours';
    case Suspendu = 'suspendu';
    case Termine = 'termine';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis',
            self::Recherche => 'Recherche',
            self::Paiement => 'Paiement',
            self::Confirme => 'Confirmé',
            self::EnCours => 'En cours',
            self::Suspendu => 'Suspendu',
            self::Termine => 'Terminé',
            self::Annule => 'Annulé',
        };
    }
}
