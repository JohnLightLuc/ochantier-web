<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une notification.
 */
enum NotificationStatus: string
{
    case EnAttente = 'en_attente';
    case Envoyee = 'envoyee';
    case Livree = 'livree';
    case Echouee = 'echouee';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Envoyee => 'Envoyée',
            self::Livree => 'Livrée',
            self::Echouee => 'Échouée',
        };
    }
}
