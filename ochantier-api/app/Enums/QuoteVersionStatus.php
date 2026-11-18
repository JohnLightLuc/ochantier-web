<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une version de devis.
 */
enum QuoteVersionStatus: string
{
    case Brouillon = 'brouillon';
    case Envoye = 'envoye';
    case Recu = 'recu';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Envoye => 'Envoyé',
            self::Recu => 'Reçu par le promoteur',
        };
    }
}
