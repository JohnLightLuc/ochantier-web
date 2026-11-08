<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un entrepreneur.
 */
enum ContractorStatus: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Exclu = 'exclu';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Exclu => 'Exclu',
        };
    }
}
