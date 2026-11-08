<?php

namespace App\Support\Promoter;

use App\Enums\QuoteRequestStatus;
use App\Enums\QuoteRequestTradeStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestTrade;
use App\Models\Worker;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Simulation locale des techniciens (application Ouvriers pas encore disponible), pour tester la demande de devis
 * de bout en bout : offres des ouvriers validés du métier, puis réalisation des devis commandés. Hors production
 * uniquement.
 */
class QuoteSimulator
{
    /**
     * Offres reçues par corps de métier (les BEST_BIDS meilleures sont montrées au promoteur).
     */
    public const BIDS_PER_TRADE = 4;

    public function __construct(private QuoteRequests $quotes, private PricingRules $pricing) {}

    /**
     * Demandes à faire avancer : en recherche (offres à recevoir) ou commandées (devis à réaliser).
     *
     * @return Builder<QuoteRequest>
     */
    public function pending(): Builder
    {
        return QuoteRequest::query()->whereIn('status', [QuoteRequestStatus::Recherche->value, QuoteRequestStatus::Commande->value]);
    }

    /**
     * Offres des techniciens pour chaque corps de métier encore sans offre. Renvoie le nombre d’offres créées.
     */
    public function bid(QuoteRequest $request): int
    {
        $created = 0;

        foreach ($request->trades()->with('trade')->where('status', QuoteRequestTradeStatus::Recherche->value)->whereDoesntHave('bids')->get() as $line) {
            $base = $this->pricing->referenceRates()->get($line->trade_id)['rate'] ?? 20000;
            $workers = Worker::query()
                ->where('verification_status', WorkerVerificationStatus::Valide->value)
                ->whereNull('merged_into_worker_id')
                ->whereHas('workerTrades', fn (Builder $query) => $query->where('trade_id', $line->trade_id))
                ->inRandomOrder()
                ->limit(self::BIDS_PER_TRADE)
                ->get();

            foreach ($workers as $worker) {
                $amount = (int) (round($base * random_int(80, 160) / 100 / 1000) * 1000);
                $this->quotes->addBid($line, $worker, max(1000, $amount), CarbonImmutable::today()->addDays(random_int(1, 7)));
                $created++;
            }
        }

        return $created;
    }

    /**
     * Réalise les devis commandés : lignes d’exemple (fournitures, main-d’œuvre, visite). Renvoie le nombre de devis.
     */
    public function complete(QuoteRequest $request): int
    {
        $lines = $request->trades()->with(['trade', 'selectedBid.worker'])->where('status', QuoteRequestTradeStatus::Commande->value)->get();

        foreach ($lines as $line) {
            /** @var QuoteRequestTrade $line */
            $daily = $this->pricing->referenceRates()->get($line->trade_id)['rate'] ?? 20000;
            $this->quotes->complete($line, [
                ['designation' => 'Fournitures · '.mb_strtolower($line->trade->label), 'unit_price' => (int) (round($daily * random_int(20, 60) / 1000) * 1000), 'quantity' => 1],
                ['designation' => 'Main-d’œuvre · '.mb_strtolower($line->trade->label), 'unit_price' => $daily, 'quantity' => random_int(5, 20)],
                ['designation' => 'Visite et établissement du devis', 'unit_price' => $line->selectedBid->amount, 'quantity' => 1],
            ], CarbonImmutable::parse($line->selectedBid->visit_date));
        }

        return $lines->count();
    }
}
