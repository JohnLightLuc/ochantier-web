<?php

namespace App\Enums;

/**
 * Valeurs de `direction` pour une transaction de paiement.
 */
enum PaymentTransactionDirection: string
{
    case Encaissement = 'encaissement';
    case Decaissement = 'decaissement';
    case Remboursement = 'remboursement';

    public function label(): string
    {
        return match ($this) {
            self::Encaissement => 'Encaissement',
            self::Decaissement => 'Décaissement',
            self::Remboursement => 'Remboursement',
        };
    }
}
