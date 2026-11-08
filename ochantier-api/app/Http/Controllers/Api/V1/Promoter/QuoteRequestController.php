<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\QuoteRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Demande de devis d’un chantier (« Offres de devis », « Devis commandé », « Devis reçu ») : la commande se paie
 * avec POST payments et quote_bid_ids (une offre par corps de métier).
 */
class QuoteRequestController extends Controller
{
    public function __construct(private QuoteRequests $quotes) {}

    public function show(Site $site): JsonResponse
    {
        $request = $this->quotes->current($site);

        abort_unless($request, 404, 'Aucune demande de devis pour ce chantier.');

        return ApiResponse::success($this->quotes->summary($request));
    }

    /**
     * Annule la demande tant qu’aucun devis n’est commandé : le chantier revient au brouillon.
     */
    public function destroy(Request $request, Site $site): JsonResponse
    {
        $this->quotes->cancel($site, $request->user());

        return ApiResponse::success(message: 'Demande de devis annulée');
    }
}
