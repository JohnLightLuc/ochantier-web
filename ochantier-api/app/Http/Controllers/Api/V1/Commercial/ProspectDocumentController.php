<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Commercial\ProspectDocumentRequest;
use App\Http\Resources\V1\Commercial\DocumentResource;
use App\Models\Document;
use App\Models\Prospect;
use App\Support\Commercial\ProspectDossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Pièces du dossier d’un prospect (« Documents et photo »).
 */
class ProspectDocumentController extends Controller
{
    public function __construct(private ProspectDossier $dossier) {}

    /**
     * Ajoute ou remplace une pièce ; le dossier brouillon est créé s’il n’existe pas encore.
     */
    public function store(ProspectDocumentRequest $request, Prospect $prospect): JsonResponse
    {
        $type = $request->documentType();
        $document = $this->dossier->attachDocument($prospect, $request->user(), $type, $request->sides(), $request->input('number'), $request->input('expires_at'));

        return DocumentResource::make($document)
            ->additional(['message' => $type->code === ProspectDossier::PHOTO_DOCUMENT ? 'Photo ajoutée' : 'Document ajouté'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retire une pièce du dossier (« Retirer »).
     */
    public function destroy(Prospect $prospect, string $document): Response
    {
        $worker = $this->dossier->draft($prospect);
        $model = $worker ? $this->dossier->activeDocuments($worker)->first(fn (Document $item) => $item->getKey() === $document) : null;

        abort_unless($model, 404, 'Document introuvable.');

        $this->dossier->removeDocument($prospect, $model);

        return response()->noContent();
    }
}
