<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un corps de métier d’une demande de devis.
 */
enum QuoteRequestTradeStatus: string
{
    case Recherche = 'recherche';
    case Commande = 'commande';
    case Realise = 'realise';

    public function label(): string
    {
        return match ($this) {
            self::Recherche => 'Recherche de technicien',
            self::Commande => 'Commandé',
            self::Realise => 'Effectué',
        };
    }
}
