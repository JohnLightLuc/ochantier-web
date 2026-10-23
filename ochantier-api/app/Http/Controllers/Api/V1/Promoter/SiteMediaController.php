<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\PromoterPhotoRequest;
use App\Http\Requests\V1\Promoter\SiteDocumentRequest;
use App\Http\Resources\V1\DocumentResource;
use App\Http\Resources\V1\Promoter\SiteDetailResource;
use App\Models\Document;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Photo du chantier et documents joints (« Ajouter une photo », « Ajouter un document »).
 */
class SiteMediaController extends Controller
{
    public function __construct(private SiteManager $sites) {}

    public function storePhoto(PromoterPhotoRequest $request, Site $site): SiteDetailResource
    {
        $site = $this->sites->setPhoto($site, $request->user(), $request->file('photo'));

        return SiteDetailResource::make($site->load(SiteController::WITH))->additional(['message' => 'Photo ajoutée']);
    }

    public function destroyPhoto(Site $site): SiteDetailResource
    {
        $site = $this->sites->removePhoto($site);

        return SiteDetailResource::make($site->load(SiteController::WITH))->additional(['message' => 'Photo retirée']);
    }

    public function storeDocument(SiteDocumentRequest $request, Site $site): JsonResponse
    {
        $document = $this->sites->attachDocument($site, $request->user(), $request->documentType(), $request->sides());

        return DocumentResource::make($document)
            ->additional(['message' => 'Document ajouté', 'meta' => ['lock_version' => $site->lock_version]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroyDocument(Request $request, Site $site, string $document): JsonResponse
    {
        $model = $this->sites->activeDocuments($site)->first(fn (Document $item) => $item->getKey() === $document);

        abort_unless($model, 404, 'Document introuvable.');

        $this->sites->removeDocument($site, $model);

        return ApiResponse::success(message: 'Document retiré');
    }
}
