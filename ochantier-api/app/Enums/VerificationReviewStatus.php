<?php

namespace App\Enums;

/**
 * Valeurs de `status` pour un contrôle de dossier.
 */
enum VerificationReviewStatus: string
{
    case Soumis = 'soumis';
    case EnControle = 'en_controle';
    case ACorriger = 'a_corriger';
    case Valide = 'valide';
    case Refuse = 'refuse';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::Soumis => 'Soumis',
            self::EnControle => 'En contrôle',
            self::ACorriger => 'À corriger',
            self::Valide => 'Validé',
            self::Refuse => 'Refusé',
            self::Annule => 'Annulé',
        };
    }
}
