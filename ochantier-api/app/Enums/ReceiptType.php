<?php

namespace App\Enums;

/**
 * Valeurs de `receipt_type` pour un reçu.
 */
enum ReceiptType: string
{
    case Recu = 'recu';
    case Facture = 'facture';
    case ReleveGains = 'releve_gains';
    case Avoir = 'avoir';

    public function label(): string
    {
        return match ($this) {
            self::Recu => 'Reçu',
            self::Facture => 'Facture',
            self::ReleveGains => 'Relevé de gains',
            self::Avoir => 'Avoir',
        };
    }
}
