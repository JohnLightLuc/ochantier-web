<?php

namespace App\Enums;

/**
 * Valeurs de `method_type` pour un moyen de paiement.
 */
enum PaymentMethodType: string
{
    case MobileMoney = 'mobile_money';
    case Virement = 'virement';
    case Carte = 'carte';

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile Money',
            self::Virement => 'Virement',
            self::Carte => 'Carte',
        };
    }
}
