<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\OfferResponseRequest;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorOfferResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Réponses de l’entrepreneur aux offres contractuelles (envoi ou brouillon, détail, suppression d’un brouillon) et
 * liste « Mes offres », qui regroupe ces réponses et les propositions de devis.
 */
class OfferResponseController extends Controller
{
    public function __construct(private ContractorOfferResponses $responses) {}

    public function store(OfferResponseRequest $request, string $offer): JsonResponse
    {
        $bid = $this->responses->respond(ProfileController::contractor($request), $offer, $request->validated());
        $draft = (bool) $request->validated('draft', false);

        return ApiResponse::success($this->responses->detail($bid), $draft ? 'Brouillon enregistré.' : 'Offre envoyée au promoteur.', status: 201);
    }

    public function show(Request $request, string $offerResponse): JsonResponse
    {
        return ApiResponse::success($this->responses->detail($this->responses->find(ProfileController::contractor($request), $offerResponse)));
    }

    public function destroy(Request $request, string $offerResponse): JsonResponse
    {
        $this->responses->deleteDraft($this->responses->find(ProfileController::contractor($request), $offerResponse));

        return ApiResponse::success(null, 'Brouillon supprimé.');
    }

    /**
     * « Mes offres » : onglet (brouillons, en_attente, acceptees, refusees, retirees) et recherche.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(ContractorOfferResponses::TABS))],
            'search' => ['nullable', 'string', 'max:100'],
        ], ['tab.in' => 'Onglet inconnu.']);
        $list = $this->responses->list(ProfileController::contractor($request), $data['tab'] ?? 'en_attente', $data['search'] ?? null);

        return ApiResponse::success($list['data'], meta: $list['meta']);
    }
}
