<?php

namespace App\Http\Resources\V1\Promoter;

use App\Http\Resources\V1\DocumentResource;
use App\Models\Document;
use App\Models\Site;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\Request;

/**
 * « Détail chantier » : la carte, plus la description, les accès, le responsable et les documents joints.
 *
 * @mixin Site
 */
class SiteDetailResource extends SiteResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sites = app(SiteManager::class);
        $responsible = $this->responsibleUser;

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'access_instructions' => $this->access_instructions,
            'responsible' => $responsible ? $sites->person($responsible, $responsible->is($request->user()) ? 'Moi-même' : null) : null,
            'documents' => $sites->activeDocuments($this->resource)
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve())
                ->values()
                ->all(),
        ];
    }
}
