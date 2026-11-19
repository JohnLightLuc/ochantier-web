<?php

namespace App\Http\Requests\V1\Contractor;

use App\Enums\ContractorKind;
use App\Enums\DocumentAudience;
use App\Http\Requests\V1\DocumentUploadRequest;

/**
 * Pièce du dossier (« Pièces », « Justificatifs »). Le numéro de la pièce d’identité est exigé pour un indépendant ;
 * il est facultatif pour le représentant d’une entreprise.
 */
class ContractorDocumentRequest extends DocumentUploadRequest
{
    protected function audiences(): array
    {
        return [DocumentAudience::Contractor];
    }

    protected function identityNumberRequired(): bool
    {
        return $this->user()->contractor?->kind !== ContractorKind::Entreprise;
    }
}
