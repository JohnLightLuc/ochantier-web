<?php

namespace App\Enums;

/**
 * Niveau de priorité ou d’urgence.
 */
enum Priority: string
{
    case Basse = 'basse';
    case Normale = 'normale';
    case Haute = 'haute';
    case Critique = 'critique';

    public function label(): string
    {
        return match ($this) {
            self::Basse => 'Basse',
            self::Normale => 'Normale',
            self::Haute => 'Haute',
            self::Critique => 'Critique',
        };
    }
}
