<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une demande de devis.
 */
enum QuoteRequestStatus: string
{
    case Recherche = 'recherche';
    case Commande = 'commande';
    case Recu = 'recu';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Recherche => 'Recherche de technicien',
            self::Commande => 'Devis commandé',
            self::Recu => 'Devis reçu',
            self::Annule => 'Annulée',
        };
    }
}
