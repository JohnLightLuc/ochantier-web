<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une transaction de paiement.
 */
enum PaymentTransactionStatus: string
{
    case Initiee = 'initiee';
    case EnAttente = 'en_attente';
    case Reussie = 'reussie';
    case Echouee = 'echouee';
    case Expiree = 'expiree';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::Initiee => 'Initiée',
            self::EnAttente => 'En attente',
            self::Reussie => 'Réussie',
            self::Echouee => 'Échouée',
            self::Expiree => 'Expirée',
            self::Annulee => 'Annulée',
        };
    }
}
