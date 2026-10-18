<?php

namespace App\Enums;

/**
 * Valeurs de `detected_by` pour une anomalie de pointage.
 */
enum AnomalyDetector: string
{
    case Systeme = 'systeme';
    case Promoteur = 'promoteur';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Systeme => 'Système',
            self::Promoteur => 'Promoteur',
            self::Admin => 'Administrateur',
        };
    }
}
