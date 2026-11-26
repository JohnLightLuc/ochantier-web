<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Models\QuoteBid;
use App\Models\QuoteVersion;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\QuoteRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Demande de devis d’un chantier (« Offres de devis », « Devis commandé », « Devis reçu ») : la commande se paie
 * avec POST payments et quote_bid_ids (une offre par corps de métier). Ensuite, le promoteur accepte ou refuse les
 * reports de visite proposés par les entrepreneurs, et accuse réception des devis.
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

    /**
     * Report de visite proposé par l’entrepreneur : decision accepter (nouvelle date) ou refuser (date convenue).
     */
    public function decideReschedule(Request $request, Site $site, string $bid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accepter', 'refuser'])]], ['decision.required' => 'Acceptez ou refusez le report.', 'decision.in' => 'Acceptez ou refusez le report.']);
        $this->quotes->decideReschedule($this->bid($site, $bid), $data['decision'] === 'accepter');

        return ApiResponse::success($this->quotes->summary($this->quotes->current($site)), $data['decision'] === 'accepter' ? 'Report de visite accepté' : 'Report de visite refusé');
    }

    /**
     * Visite déclarée faite par le professionnel : « Oui, la visite a eu lieu » ou « Non, personne n’est venu » (motif).
     */
    public function confirmVisit(Request $request, Site $site, string $bid): JsonResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'boolean'],
            'reason' => ['required_if:confirmed,false', 'nullable', 'string', 'min:5', 'max:500'],
        ], [
            'confirmed.required' => 'Indiquez si la visite a eu lieu.',
            'reason.required_if' => 'Expliquez ce qui s’est passé : le Back Office examinera votre signalement.',
            'reason.min' => 'Expliquez ce qui s’est passé en quelques mots.',
        ]);
        $confirmed = (bool) $data['confirmed'];
        $this->quotes->confirmVisit($this->bid($site, $bid), $confirmed, $confirmed ? null : $data['reason']);

        return ApiResponse::success($this->quotes->summary($this->quotes->current($site)), $confirmed ? 'Visite confirmée' : 'Signalement envoyé : le Back Office va examiner la visite');
    }

    /**
     * « Accuser réception » d’une version de devis envoyée.
     */
    public function acknowledge(Site $site, string $version): JsonResponse
    {
        $model = str($version)->isUuid()
            ? QuoteVersion::query()->whereKey($version)->whereHas('quoteRequestTrade.quoteRequest', fn ($query) => $query->where('site_id', $site->getKey()))->first()
            : null;

        abort_unless($model, 404, 'Devis introuvable.');

        $this->quotes->acknowledge($model);

        return ApiResponse::success($this->quotes->summary($this->quotes->current($site)), 'Réception du devis confirmée');
    }

    private function bid(Site $site, string $id): QuoteBid
    {
        $bid = str($id)->isUuid()
            ? QuoteBid::query()->whereKey($id)->whereHas('quoteRequestTrade.quoteRequest', fn ($query) => $query->where('site_id', $site->getKey()))->first()
            : null;

        abort_unless($bid, 404, 'Proposition introuvable.');

        return $bid;
    }
}
