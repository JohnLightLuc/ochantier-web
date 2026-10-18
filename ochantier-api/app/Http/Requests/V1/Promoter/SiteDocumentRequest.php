<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\DocumentAudience;
use App\Http\Requests\V1\DocumentUploadRequest;

/**
 * Document joint au chantier (plan, devis, permis, cahier des charges, photos…).
 */
class SiteDocumentRequest extends DocumentUploadRequest
{
    protected function audiences(): array
    {
        return [DocumentAudience::Site];
    }
}
