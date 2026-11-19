<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une recherche d’effectifs d’entrepreneur.
 */
enum StaffingRequestStatus: string
{
    case Recherche = 'recherche';
    case Offre = 'offre';
    case Payee = 'payee';
    case Refusee = 'refusee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Recherche => 'Recherche en cours',
            self::Offre => 'Offre reçue',
            self::Payee => 'Réservation confirmée',
            self::Refusee => 'Offre refusée',
            self::Annulee => 'Annulée',
        };
    }
}
