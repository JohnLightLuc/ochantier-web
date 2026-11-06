<?php

namespace App\Enums;

/**
 * Valeurs de `origin` pour un remboursement.
 */
enum RefundOrigin: string
{
    case Regularisation = 'regularisation';
    case Manuel = 'manuel';
    case Litige = 'litige';

    public function label(): string
    {
        return match ($this) {
            self::Regularisation => 'Fin de mission',
            self::Manuel => 'Manuel',
            self::Litige => 'Litige',
        };
    }
}
