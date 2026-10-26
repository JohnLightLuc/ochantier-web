<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une demande de changement de zone d’un commercial.
 */
enum ZoneRequestStatus: string
{
    case EnAttente = 'en_attente';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente de validation',
            self::Acceptee => 'Acceptée',
            self::Refusee => 'Refusée',
            self::Annulee => 'Annulée',
        };
    }
}
