<?php

namespace App\Enums;

/**
 * Valeurs de `rating_scope` pour une évaluation.
 */
enum RatingScope: string
{
    case Individuel = 'individuel';
    case Equipe = 'equipe';

    public function label(): string
    {
        return match ($this) {
            self::Individuel => 'Individuel',
            self::Equipe => 'Équipe',
        };
    }
}
