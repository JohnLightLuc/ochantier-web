<?php

namespace App\Enums;

/**
 * Valeurs de `promoter_decision` pour un remplacement.
 */
enum ReplacementPromoterDecision: string
{
    case EnAttente = 'en_attente';
    case Accepte = 'accepte';
    case Refuse = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Accepte => 'Accepté',
            self::Refuse => 'Refusé',
        };
    }
}
