<?php

namespace App\Http\Requests\V1\Commercial;

use App\Enums\DocumentAudience;
use App\Http\Requests\V1\DocumentUploadRequest;

/**
 * Pièce du dossier ouvrier d’un prospect (« Documents et photo »).
 */
class ProspectDocumentRequest extends DocumentUploadRequest
{
    protected function audiences(): array
    {
        return [DocumentAudience::Worker];
    }
}
