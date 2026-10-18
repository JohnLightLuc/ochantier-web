<?php

namespace App\Enums;

/**
 * Valeurs de `verification_status` pour un document.
 */
enum DocumentVerificationStatus: string
{
    case EnAttente = 'en_attente';
    case Valide = 'valide';
    case Rejete = 'rejete';
    case Expire = 'expire';
    case Remplace = 'remplace';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
            self::Expire => 'Expiré',
            self::Remplace => 'Remplacé',
        };
    }
}
