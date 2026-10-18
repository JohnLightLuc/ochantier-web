<?php

namespace App\Enums;

/**
 * Valeurs de `user_type` pour un utilisateur des applications.
 */
enum UserType: string
{
    case Promoter = 'promoter';
    case Commercial = 'commercial';
    case Worker = 'worker';

    public function label(): string
    {
        return match ($this) {
            self::Promoter => 'Promoteur',
            self::Commercial => 'Commercial',
            self::Worker => 'Ouvrier',
        };
    }
}
