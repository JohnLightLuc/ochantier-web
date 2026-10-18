<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un écart de rapprochement.
 */
enum ReconciliationItemStatus: string
{
    case Ouvert = 'ouvert';
    case Resolu = 'resolu';
    case Accepte = 'accepte';

    public function label(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::Resolu => 'Résolu',
            self::Accepte => 'Accepté',
        };
    }
}
