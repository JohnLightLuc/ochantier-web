<?php

namespace App\Enums;

/**
 * Pièces du dossier KYC d’un promoteur, dans l’ordre d’affichage.
 */
enum DocumentType: string
{
    case TradeRegister = 'trade_register';
    case RepresentativeId = 'representative_id';
    case TaxCertificate = 'tax_certificate';
    case ProofOfAddress = 'proof_of_address';
    case Additional = 'additional';

    public function label(): string
    {
        return match ($this) {
            self::TradeRegister => 'Registre de commerce',
            self::RepresentativeId => 'Pièce d’identité du représentant',
            self::TaxCertificate => 'Attestation fiscale',
            self::ProofOfAddress => 'Justificatif d’adresse',
            self::Additional => 'Document complémentaire',
        };
    }

    public function isRequired(): bool
    {
        return $this !== self::Additional;
    }
}
