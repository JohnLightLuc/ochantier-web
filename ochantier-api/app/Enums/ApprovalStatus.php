<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une approbation.
 */
enum ApprovalStatus: string
{
    case EnAttente = 'en_attente';
    case Approuve = 'approuve';
    case Refuse = 'refuse';
    case Expire = 'expire';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Approuve => 'Approuvé',
            self::Refuse => 'Refusé',
            self::Expire => 'Expiré',
        };
    }
}
