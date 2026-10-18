<?php

namespace App\Enums;

/**
 * Valeurs de `app_name` pour la table `app_versions`.
 */
enum AppName: string
{
    case Promoteur = 'promoteur';
    case Commercial = 'commercial';
    case Ouvrier = 'ouvrier';

    public function label(): string
    {
        return match ($this) {
            self::Promoteur => 'Promoteur',
            self::Commercial => 'Commercial',
            self::Ouvrier => 'Ouvrier',
        };
    }
}
