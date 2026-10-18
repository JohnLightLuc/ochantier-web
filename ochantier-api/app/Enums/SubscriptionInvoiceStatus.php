<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une facture d’abonnement.
 */
enum SubscriptionInvoiceStatus: string
{
    case APayer = 'a_payer';
    case Payee = 'payee';
    case EnRetard = 'en_retard';
    case Annulee = 'annulee';
    case Remboursee = 'remboursee';

    public function label(): string
    {
        return match ($this) {
            self::APayer => 'À payer',
            self::Payee => 'Payée',
            self::EnRetard => 'En retard',
            self::Annulee => 'Annulée',
            self::Remboursee => 'Remboursée',
        };
    }
}
