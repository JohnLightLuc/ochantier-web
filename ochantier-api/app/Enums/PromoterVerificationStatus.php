<?php

namespace App\Enums;

/**
 * Statut de contrôle du dossier d’un promoteur ou d’une organisation.
 */
enum PromoterVerificationStatus: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case EnControle = 'en_controle';
    case ACorriger = 'a_corriger';
    case Valide = 'valide';
    case Refuse = 'refuse';
    case Suspendu = 'suspendu';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis',
            self::EnControle => 'En contrôle',
            self::ACorriger => 'À corriger',
            self::Valide => 'Validé',
            self::Refuse => 'Refusé',
            self::Suspendu => 'Suspendu',
        };
    }
}
