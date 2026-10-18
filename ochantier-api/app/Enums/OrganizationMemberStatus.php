<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un membre d’organisation.
 */
enum OrganizationMemberStatus: string
{
    case Invite = 'invite';
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Revoque = 'revoque';

    public function label(): string
    {
        return match ($this) {
            self::Invite => 'Invité',
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Revoque => 'Révoqué',
        };
    }
}
