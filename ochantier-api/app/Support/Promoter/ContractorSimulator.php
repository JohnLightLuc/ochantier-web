<?php

namespace App\Support\Promoter;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\PricingVariable;
use App\Models\Contractor;
use App\Models\Offer;
use App\Support\Pricing\PricingRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Simulation locale des entrepreneurs (application Ouvriers pas encore disponible), pour tester l’offre
 * contractuelle de bout en bout : entrepreneurs de démonstration au besoin, puis leurs offres. Hors production.
 */
class ContractorSimulator
{
    /**
     * Offres reçues par offre contractuelle (les BEST_BIDS meilleures sont montrées au promoteur).
     */
    public const BIDS_PER_OFFER = 4;

    /**
     * Entrepreneurs de démonstration : nom, type, note, chantiers réalisés, taille d’équipe.
     *
     * @var list<array{0: string, 1: ContractorKind, 2: float, 3: int, 4: int}>
     */
    private const DEMO = [
        ['BTP Lagune SARL', ContractorKind::Entreprise, 4.8, 42, 12],
        ['Ivoire Bâtisseurs', ContractorKind::Entreprise, 4.6, 27, 9],
        ['Konan Construction', ContractorKind::Independant, 4.7, 15, 6],
        ['Sahel Génie Civil', ContractorKind::Entreprise, 4.3, 33, 15],
    ];

    public function __construct(private ContractorOffers $offers, private PricingRules $pricing) {}

    /**
     * Offres contractuelles publiées sans offre d’entrepreneur.
     *
     * @return Builder<Offer>
     */
    public function pending(): Builder
    {
        return Offer::query()
            ->where('offer_type', OfferType::Contractuelle->value)
            ->where('status', OfferStatus::Publiee->value)
            ->whereNull('contractor_bid_id')
            ->whereDoesntHave('contractorBids');
    }

    /**
     * Offres des entrepreneurs, autour du budget du promoteur (hors commission). Renvoie le nombre d’offres créées.
     */
    public function bid(Offer $offer): int
    {
        $site = $offer->site;
        $base = (int) round(($offer->contract_total_amount ?? 1000000) / (1 + $this->pricing->value(PricingVariable::Y) / 100));
        $days = max(1, (int) $site->planned_duration_days);

        $contractors = $this->contractors();

        foreach ($contractors as $contractor) {
            $this->offers->addBid(
                $offer,
                $contractor,
                (int) (round($base * random_int(85, 115) / 100 / 1000) * 1000),
                max(1, $days + random_int(-5, 10)),
                max(1, (int) $contractor->team_size + random_int(-2, 2)),
            );
        }

        return $contractors->count();
    }

    /**
     * Entrepreneurs actifs, complétés par ceux de démonstration.
     *
     * @return Collection<int, Contractor>
     */
    private function contractors(): Collection
    {
        foreach (self::DEMO as [$name, $kind, $rating, $sites, $team]) {
            if (Contractor::query()->where('status', ContractorStatus::Actif->value)->count() >= self::BIDS_PER_OFFER) {
                break;
            }

            Contractor::query()->firstOrCreate(['name' => $name], [
                'reference' => sprintf('ENT-%05d', DB::scalar("SELECT nextval('contractor_reference_seq')")),
                'kind' => $kind,
                'rating_avg' => $rating,
                'rating_count' => $sites,
                'completed_sites_count' => $sites,
                'team_size' => $team,
                'status' => ContractorStatus::Actif,
            ]);
        }

        return Contractor::query()->where('status', ContractorStatus::Actif->value)->inRandomOrder()->limit(self::BIDS_PER_OFFER)->get();
    }
}
