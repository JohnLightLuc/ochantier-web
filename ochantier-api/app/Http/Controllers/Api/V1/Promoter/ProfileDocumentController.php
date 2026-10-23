<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\PromoterDocumentRequest;
use App\Http\Resources\V1\Promoter\PromoterProfileResource;
use App\Support\Promoter\PromoterAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pièces justificatives du promoteur et de son entreprise (« Téléverser », « Remplacer », « Ajouter d’autres
 * documents »). Chaque réponse renvoie le profil à jour (avancement et état de vérification).
 */
class ProfileDocumentController extends Controller
{
    public function __construct(private PromoterAccount $account) {}

    public function store(PromoterDocumentRequest $request): JsonResponse
    {
        $profile = ProfileController::profile($request);
        $document = $this->account->attachDocument($profile, $request->user(), $request->documentType(), $request->sides(), $request->input('number'), $request->input('expires_at'));

        return PromoterProfileResource::make($profile->refresh()->load(ProfileController::WITH))
            ->additional(['message' => $document->documentType->label.' ajouté', 'meta' => ['document_id' => $document->getKey()]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $document): PromoterProfileResource
    {
        $profile = ProfileController::profile($request);
        $model = $this->account->findDocument($profile, $document);

        abort_unless($model, 404, 'Document introuvable.');

        $this->account->removeDocument($profile, $model);

        return PromoterProfileResource::make($profile->refresh()->load(ProfileController::WITH))->additional(['message' => 'Document retiré']);
    }
}
