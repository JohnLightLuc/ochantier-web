<?php

namespace App\Support\Promoter;

use App\Enums\QuoteRequestStatus;
use App\Models\Commune;
use App\Models\Site;
use App\Support\Contractor\ContractorNotifier;
use App\Support\Contractor\ContractorOpportunities;
use Illuminate\Support\Facades\DB;

/**
 * Commune d’un chantier déduite de sa position GPS quand l’application ne l’envoie pas. Les professionnels sont
 * ciblés par la ville de la commune : sans elle, aucune demande de devis ni offre contractuelle ne leur parvient.
 */
class SiteCommunes
{
    /**
     * Distance maximale entre le chantier et le centre de la commune retenue (km).
     */
    public const MAX_DISTANCE_KM = 30;

    /**
     * Commune active la plus proche de la position du chantier, à moins de MAX_DISTANCE_KM, ou null.
     */
    public function nearest(Site $site): ?Commune
    {
        $id = DB::scalar(
            'SELECT c.id FROM communes c, sites s
             WHERE s.id = ? AND s.location IS NOT NULL AND c.is_active AND c.location IS NOT NULL
               AND ST_DWithin(c.location, s.location, ?)
             ORDER BY c.location <-> s.location LIMIT 1',
            [$site->getKey(), self::MAX_DISTANCE_KM * 1000],
        );

        return $id ? Commune::query()->with('city')->find($id) : null;
    }

    /**
     * Renseigne la commune d’un chantier qui n’en a pas, à partir de sa position. Renvoie la commune retenue.
     */
    public function fill(Site $site): ?Commune
    {
        if ($site->commune_id) {
            return null;
        }

        $commune = $this->nearest($site);

        if ($commune) {
            $site->forceFill(['commune_id' => $commune->getKey()])->saveQuietly();
        }

        return $commune;
    }

    /**
     * Prévient les professionnels des demandes de devis et des offres contractuelles du chantier encore ouvertes
     * (sans doublon : chaque professionnel n’est prévenu qu’une fois par demande ou par offre). Renvoie le nombre
     * de demandes et d’offres concernées.
     */
    public function notifyOpenRequests(Site $site): int
    {
        $notifier = app(ContractorNotifier::class);
        $count = 0;

        foreach ($site->quoteRequests()->where('status', QuoteRequestStatus::Recherche->value)->where('response_deadline', '>', now())->get() as $request) {
            $notifier->newQuoteRequest($request);
            $count++;
        }

        foreach (app(ContractorOpportunities::class)->openOffers()->where('site_id', $site->getKey())->get() as $offer) {
            $quote = $site->quoteRequests()->where('status', '!=', QuoteRequestStatus::Annule->value)->latest('submitted_at')->first();
            $notifier->newContractOffer($offer, $quote ? $quote->trades()->pluck('trade_id')->all() : []);
            $count++;
        }

        return $count;
    }
}
