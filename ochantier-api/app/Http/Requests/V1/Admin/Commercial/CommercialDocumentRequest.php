<?php

namespace App\Http\Requests\V1\Admin\Commercial;

use App\Enums\DocumentAudience;
use App\Http\Requests\V1\DocumentUploadRequest;

/**
 * Pièce du dossier d’un commercial.
 */
class CommercialDocumentRequest extends DocumentUploadRequest
{
    protected function audiences(): array
    {
        return [DocumentAudience::Commercial];
    }
}
