<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour une mission.
 */
enum MissionStatus: string
{
    case EnAttenteAcompte = 'en_attente_acompte';
    case Confirmee = 'confirmee';
    case EnCours = 'en_cours';
    case Suspendue = 'suspendue';
    case Terminee = 'terminee';
    case Annulee = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::EnAttenteAcompte => 'En attente d’acompte',
            self::Confirmee => 'Confirmée',
            self::EnCours => 'En cours',
            self::Suspendue => 'Suspendue',
            self::Terminee => 'Terminée',
            self::Annulee => 'Annulée',
        };
    }
}
