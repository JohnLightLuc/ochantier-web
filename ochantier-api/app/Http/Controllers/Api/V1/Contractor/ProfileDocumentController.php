<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\ContractorDocumentRequest;
use App\Http\Resources\V1\Contractor\ContractorProfileResource;
use App\Support\Contractor\ContractorAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pièces du dossier de l’entrepreneur (« Pièces », « Justificatifs ») : ajout, remplacement et retrait. Chaque
 * réponse renvoie le profil à jour (avancement et état de la vérification).
 */
class ProfileDocumentController extends Controller
{
    public function __construct(private ContractorAccount $account) {}

    public function store(ContractorDocumentRequest $request): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $document = $this->account->attachDocument($contractor, $request->user(), $request->documentType(), $request->sides(), $request->input('number'), $request->input('expires_at'));

        return ContractorProfileResource::make($this->account->fresh($contractor))
            ->additional(['message' => $document->documentType->label.' ajouté', 'meta' => ['document_id' => $document->getKey()]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $document): ContractorProfileResource
    {
        $contractor = ProfileController::contractor($request);
        $model = str($document)->isUuid() ? $this->account->findDocument($contractor, $document) : null;

        abort_unless($model, 404, 'Pièce introuvable.');

        $this->account->removeDocument($contractor, $model);

        return ContractorProfileResource::make($this->account->fresh($contractor))->additional(['message' => 'Pièce retirée']);
    }
}
