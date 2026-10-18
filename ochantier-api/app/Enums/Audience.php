<?php

namespace App\Enums;

/**
 * Public visé par un texte légal ou un article d’aide.
 */
enum Audience: string
{
    case Promoter = 'promoter';
    case Commercial = 'commercial';
    case Worker = 'worker';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Promoter => 'Promoteur',
            self::Commercial => 'Commercial',
            self::Worker => 'Ouvrier',
            self::All => 'Tous',
        };
    }
}
