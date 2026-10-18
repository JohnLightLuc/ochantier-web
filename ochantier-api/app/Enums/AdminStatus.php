<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un administrateur.
 */
enum AdminStatus: string
{
    case Invite = 'invite';
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Desactive = 'desactive';

    public function label(): string
    {
        return match ($this) {
            self::Invite => 'Invité',
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Desactive => 'Désactivé',
        };
    }
}
