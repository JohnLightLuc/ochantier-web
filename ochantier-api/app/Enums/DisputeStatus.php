<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un litige.
 */
enum DisputeStatus: string
{
    case Ouvert = 'ouvert';
    case EnInstruction = 'en_instruction';
    case Resolu = 'resolu';
    case Clos = 'clos';
    case EnRecours = 'en_recours';

    public function label(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::EnInstruction => 'En instruction',
            self::Resolu => 'Résolu',
            self::Clos => 'Clos',
            self::EnRecours => 'En recours',
        };
    }
}
