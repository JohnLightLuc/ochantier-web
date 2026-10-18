<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un ticket de support.
 */
enum TicketStatus: string
{
    case Ouvert = 'ouvert';
    case EnCours = 'en_cours';
    case EnAttenteUtilisateur = 'en_attente_utilisateur';
    case Escalade = 'escalade';
    case Resolu = 'resolu';
    case Ferme = 'ferme';

    public function label(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::EnCours => 'En cours',
            self::EnAttenteUtilisateur => 'En attente de l’utilisateur',
            self::Escalade => 'Escaladé',
            self::Resolu => 'Résolu',
            self::Ferme => 'Fermé',
        };
    }
}
