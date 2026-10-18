<?php

namespace App\Enums;

/**
 * Valeurs de `decision` pour une réponse d’ouvrier.
 */
enum WorkerResponseDecision: string
{
    case Accepte = 'accepte';
    case Refuse = 'refuse';
    case Indisponible = 'indisponible';

    public function label(): string
    {
        return match ($this) {
            self::Accepte => 'Accepté',
            self::Refuse => 'Refusé',
            self::Indisponible => 'Indisponible',
        };
    }
}
