<?php

namespace App\Enums;

/**
 * Statut de contrôle du dossier d’un entrepreneur (KYB pour une entreprise, KYC pour un indépendant).
 */
enum ContractorVerificationStatus: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case EnControle = 'en_controle';
    case ACorriger = 'a_corriger';
    case Valide = 'valide';
    case Refuse = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis',
            self::EnControle => 'En contrôle',
            self::ACorriger => 'À corriger',
            self::Valide => 'Validé',
            self::Refuse => 'Refusé',
        };
    }

    /**
     * Dossier en attente d’une décision du Back Office.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Soumis, self::EnControle], true);
    }
}
