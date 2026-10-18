<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un remboursement.
 */
enum RefundStatus: string
{
    case Demande = 'demande';
    case Approuve = 'approuve';
    case Refuse = 'refuse';
    case Execute = 'execute';
    case Echoue = 'echoue';

    public function label(): string
    {
        return match ($this) {
            self::Demande => 'Demandé',
            self::Approuve => 'Approuvé',
            self::Refuse => 'Refusé',
            self::Execute => 'Exécuté',
            self::Echoue => 'Échoué',
        };
    }
}
