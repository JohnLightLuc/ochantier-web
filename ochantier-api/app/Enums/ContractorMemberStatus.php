<?php

namespace App\Enums;

/**
 * Statut d’un collaborateur du compte d’un entrepreneur.
 */
enum ContractorMemberStatus: string
{
    case Invite = 'invite';
    case Actif = 'actif';
    case Revoque = 'revoque';

    public function label(): string
    {
        return match ($this) {
            self::Invite => 'Invité',
            self::Actif => 'Actif',
            self::Revoque => 'Retiré',
        };
    }
}
