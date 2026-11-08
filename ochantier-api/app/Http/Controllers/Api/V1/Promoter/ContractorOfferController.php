<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Models\ContractorBid;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\ContractorOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Offres d’entrepreneurs » d’une offre contractuelle publiée : les meilleures offres et le choix de l’une d’elles.
 * L’acompte se paie avec POST payments et contractor_bid_id.
 */
class ContractorOfferController extends Controller
{
    public function __construct(private ContractorOffers $offers) {}

    public function index(Site $site): JsonResponse
    {
        $offer = $this->offers->openOffer($site);

        abort_unless($offer, 404, 'Aucune offre contractuelle en recherche d’entrepreneurs.');

        return ApiResponse::success($this->offers->summary($offer));
    }

    public function choose(Request $request, Site $site, string $bid): JsonResponse
    {
        $model = ContractorBid::query()->whereKey($bid)->whereHas('offer', fn ($query) => $query->where('site_id', $site->getKey()))->first();

        abort_unless($model, 404, 'Offre d’entrepreneur introuvable.');

        $this->offers->choose($site, $model, $request->user());

        return ApiResponse::success($this->offers->summary($this->offers->openOffer($site)), 'Offre choisie · payez l’acompte pour confirmer l’entrepreneur');
    }
}
