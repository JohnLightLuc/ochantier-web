<?php

namespace App\Enums;

/**
 * Pièces du dossier d’un commercial, dans l’ordre d’affichage.
 */
enum SalesAgentDocumentType: string
{
    case IdFront = 'id_front';
    case IdBack = 'id_back';
    case Photo = 'photo';
    case ProofOfAddress = 'proof_of_address';
    case Contract = 'contract';
    case Cv = 'cv';

    public function label(): string
    {
        return match ($this) {
            self::IdFront => 'Pièce d’identité (recto)',
            self::IdBack => 'Pièce d’identité (verso)',
            self::Photo => 'Photo d’identité',
            self::ProofOfAddress => 'Justificatif de domicile',
            self::Contract => 'Contrat ou convention',
            self::Cv => 'Curriculum vitae',
        };
    }

    public function kind(): string
    {
        return match ($this) {
            self::IdFront, self::IdBack => 'Pièce d’identité',
            self::Photo => 'Photo',
            self::ProofOfAddress => 'Justificatif',
            self::Contract => 'Contrat',
            self::Cv => 'CV',
        };
    }
}
