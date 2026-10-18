<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un utilisateur des applications.
 */
enum UserStatus: string
{
    case EnAttenteActivation = 'en_attente_activation';
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Desactive = 'desactive';

    public function label(): string
    {
        return match ($this) {
            self::EnAttenteActivation => 'En attente d’activation',
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Desactive => 'Désactivé',
        };
    }
}
