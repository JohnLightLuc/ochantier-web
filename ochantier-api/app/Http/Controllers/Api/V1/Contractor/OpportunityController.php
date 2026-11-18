<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\OpportunityIndexRequest;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorOpportunities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Opportunités de l’entrepreneur : demandes de devis et offres contractuelles compatibles avec ses métiers et sa
 * zone d’intervention, consultables avant la vérification du profil.
 */
class OpportunityController extends Controller
{
    public function __construct(private ContractorOpportunities $opportunities) {}

    public function index(OpportunityIndexRequest $request): JsonResponse
    {
        $list = $this->opportunities->list(ProfileController::contractor($request), $request->validated('kind') ?? ContractorOpportunities::QUOTES, $request->validated('search'));

        return ApiResponse::success($list['data'], meta: $list['meta']);
    }

    public function showQuote(Request $request, string $quote): JsonResponse
    {
        $detail = str($quote)->isUuid() ? $this->opportunities->quote(ProfileController::contractor($request), $quote) : null;

        abort_unless($detail, 404, 'Demande de devis introuvable.');

        return ApiResponse::success($detail);
    }

    public function showOffer(Request $request, string $offer): JsonResponse
    {
        $detail = str($offer)->isUuid() ? $this->opportunities->offer(ProfileController::contractor($request), $offer) : null;

        abort_unless($detail, 404, 'Offre contractuelle introuvable.');

        return ApiResponse::success($detail);
    }
}
