<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\DocumentAudience;
use App\Http\Requests\V1\DocumentUploadRequest;

/**
 * Pièce justificative du promoteur ou de son entreprise (« Documents d’identité », « Documents de l’entreprise »).
 * Le numéro de la pièce d’identité est facultatif.
 */
class PromoterDocumentRequest extends DocumentUploadRequest
{
    protected function audiences(): array
    {
        return [DocumentAudience::Promoter, DocumentAudience::Organization];
    }

    protected function identityNumberRequired(): bool
    {
        return false;
    }
}
