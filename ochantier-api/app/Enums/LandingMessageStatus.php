<?php

namespace App\Enums;

/**
 * Statut d’un message du formulaire de contact du site public.
 */
enum LandingMessageStatus: string
{
    case Nouveau = 'nouveau';
    case EnCours = 'en_cours';
    case Traite = 'traite';

    public function label(): string
    {
        return match ($this) {
            self::Nouveau => 'Nouveau',
            self::EnCours => 'En cours',
            self::Traite => 'Traité',
        };
    }
}
