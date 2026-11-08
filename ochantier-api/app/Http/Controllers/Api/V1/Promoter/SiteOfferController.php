<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\OfferDraftRequest;
use App\Http\Resources\V1\Promoter\SiteDetailResource;
use App\Models\Site;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\SiteManager;
use App\Support\Promoter\SitePublisher;
use Illuminate\Http\Request;

/**
 * Besoins du chantier et publication (« Enregistrer le brouillon », « Publier »).
 */
class SiteOfferController extends Controller
{
    public function __construct(private OfferDraft $drafts, private SiteManager $sites, private SitePublisher $publisher) {}

    public function update(OfferDraftRequest $request, Site $site): SiteDetailResource
    {
        $this->sites->ensureEditable($site);
        $site = $this->drafts->save($site, $request->validated());

        return SiteDetailResource::make($site->load(SiteController::WITH))->additional(['message' => 'Brouillon enregistré']);
    }

    /**
     * Publie le chantier : l’offre et ses besoins sont transmis au Back office, puis la recherche d’ouvriers démarre.
     * Une offre contractuelle sans devis lance plutôt la demande de devis (« Rechercher un technicien »).
     */
    public function publish(Request $request, Site $site, QuoteRequests $quotes): SiteDetailResource
    {
        if ($this->drafts->requestsQuote($site)) {
            $quotes->submit($site, $request->user());

            return SiteDetailResource::make($site->refresh()->load(SiteController::WITH))
                ->additional(['message' => 'Demande de devis envoyée — recherche de technicien lancée']);
        }

        $this->publisher->publish($site, $request->user());

        return SiteDetailResource::make($site->refresh()->load(SiteController::WITH))
            ->additional(['message' => 'Chantier publié — recherche d’ouvriers lancée']);
    }
}
