<?php

namespace App\Enums;

/**
 * Pièces justificatives du dossier d’un ouvrier, dans l’ordre d’affichage.
 */
enum WorkerDocumentType: string
{
    case IdFront = 'id_front';
    case IdBack = 'id_back';
    case ProofOfAddress = 'proof_of_address';
    case Cv = 'cv';
    case Diploma = 'diploma';
    case ProfessionalCertificate = 'professional_certificate';

    public function label(): string
    {
        return match ($this) {
            self::IdFront => 'Pièce d’identité (recto)',
            self::IdBack => 'Pièce d’identité (verso)',
            self::ProofOfAddress => 'Justificatif de domicile',
            self::Cv => 'Curriculum vitae',
            self::Diploma => 'Certificat ou diplôme',
            self::ProfessionalCertificate => 'Attestation professionnelle',
        };
    }

    public function kind(): string
    {
        return match ($this) {
            self::IdFront, self::IdBack => 'Pièce d’identité',
            self::ProofOfAddress => 'Justificatif',
            self::Cv => 'CV',
            self::Diploma => 'Diplôme',
            self::ProfessionalCertificate => 'Attestation',
        };
    }

    /**
     * L’attestation professionnelle peut manquer sans bloquer la validation du profil.
     */
    public function isRequired(): bool
    {
        return $this !== self::ProfessionalCertificate;
    }
}
