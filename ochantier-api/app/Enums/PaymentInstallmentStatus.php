<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un versement d’un paiement flexible.
 */
enum PaymentInstallmentStatus: string
{
    case APayer = 'a_payer';
    case Payee = 'payee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::APayer => 'À payer',
            self::Payee => 'Payé',
            self::Annulee => 'Annulé',
        };
    }
}
