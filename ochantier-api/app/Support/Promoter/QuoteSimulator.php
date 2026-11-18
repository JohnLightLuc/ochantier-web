<?php

namespace App\Support\Promoter;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\QuoteBidStatus;
use App\Enums\QuoteRequestStatus;
use App\Enums\QuoteRequestTradeStatus;
use App\Models\Contractor;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestTrade;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Simulation locale des entrepreneurs, pour tester la demande de devis de bout en bout : propositions
 * d’entrepreneurs vérifiés du métier et de la ville (créés au besoin), puis visite et devis des propositions
 * commandées. Hors production uniquement.
 */
class QuoteSimulator
{
    /**
     * Propositions reçues par corps de métier (les BEST_BIDS meilleures sont montrées au promoteur).
     */
    public const BIDS_PER_TRADE = 4;

    public function __construct(private QuoteRequests $quotes, private PricingRules $pricing) {}

    /**
     * Demandes à faire avancer : en recherche (propositions à recevoir) ou commandées (devis à réaliser).
     *
     * @return Builder<QuoteRequest>
     */
    public function pending(): Builder
    {
        return QuoteRequest::query()->whereIn('status', [QuoteRequestStatus::Recherche->value, QuoteRequestStatus::Commande->value]);
    }

    /**
     * Propositions pour chaque corps de métier encore sans proposition. Renvoie le nombre de propositions créées.
     */
    public function bid(QuoteRequest $request): int
    {
        $created = 0;
        $city = $request->site->commune?->city_id;

        foreach ($request->trades()->with('trade')->where('status', QuoteRequestTradeStatus::Recherche->value)->whereDoesntHave('bids', fn (Builder $query) => $query->where('status', QuoteBidStatus::Proposee->value))->get() as $line) {
            $base = $this->pricing->referenceRates()->get($line->trade_id)['rate'] ?? 20000;

            foreach ($this->contractors($line, $city) as $contractor) {
                $fee = (int) (round($base * random_int(80, 160) / 100 / 1000) * 1000);
                $this->quotes->addBid($line, $contractor, max(1000, $fee), CarbonImmutable::today()->addDays(random_int(1, 7)), QuoteRequests::SLOTS[array_rand(QuoteRequests::SLOTS)], '2 h');
                $created++;
            }
        }

        return $created;
    }

    /**
     * Réalise les devis commandés : visite, puis devis d’exemple (fournitures, main-d’œuvre, visite) envoyé.
     * Renvoie le nombre de devis.
     */
    public function complete(QuoteRequest $request): int
    {
        $lines = $request->trades()->with(['trade', 'selectedBid.contractor', 'selectedBid.worker'])->where('status', QuoteRequestTradeStatus::Commande->value)->get();

        foreach ($lines as $line) {
            /** @var QuoteRequestTrade $line */
            $daily = $this->pricing->referenceRates()->get($line->trade_id)['rate'] ?? 20000;
            $this->quotes->complete($line, [
                ['designation' => 'Fournitures · '.mb_strtolower($line->trade->label), 'unit' => 'forfait', 'unit_price' => (int) (round($daily * random_int(20, 60) / 1000) * 1000), 'quantity' => 1],
                ['designation' => 'Main-d’œuvre · '.mb_strtolower($line->trade->label), 'unit' => 'jour', 'unit_price' => $daily, 'quantity' => random_int(5, 20)],
                ['designation' => 'Visite et établissement du devis', 'unit' => 'forfait', 'unit_price' => $line->selectedBid->fee_amount ?? $line->selectedBid->amount, 'quantity' => 1],
            ], CarbonImmutable::parse($line->selectedBid->visit_date));
        }

        return $lines->count();
    }

    /**
     * Entrepreneurs vérifiés du métier et de la ville, sans proposition sur ce corps de métier, complétés par des
     * entrepreneurs de démonstration.
     *
     * @return Collection<int, Contractor>
     */
    private function contractors(QuoteRequestTrade $line, ?string $city)
    {
        $eligible = fn () => Contractor::query()
            ->where('status', ContractorStatus::Actif->value)
            ->where('verification_status', ContractorVerificationStatus::Valide->value)
            ->whereHas('trades', fn (Builder $query) => $query->whereKey($line->trade_id))
            ->when($city, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('city_id', $city)->orWhereHas('zones', fn (Builder $query) => $query->whereKey($city))))
            ->whereDoesntHave('quoteBids', fn (Builder $query) => $query->where('quote_request_trade_id', $line->getKey()));

        for ($index = $eligible()->count() + 1; $index <= self::BIDS_PER_TRADE; $index++) {
            $contractor = Contractor::query()->create([
                'reference' => sprintf('ENT-%05d', DB::scalar("SELECT nextval('contractor_reference_seq')")),
                'kind' => $index % 2 ? ContractorKind::Independant : ContractorKind::Entreprise,
                'name' => $line->trade->label.' démo '.sprintf('%02d', random_int(1, 99)),
                'city_id' => $city,
                'experience_years' => random_int(3, 20),
                'rating_avg' => random_int(35, 50) / 10,
                'rating_count' => random_int(3, 40),
                'status' => ContractorStatus::Actif,
                'verification_status' => ContractorVerificationStatus::Valide,
                'validated_at' => now(),
            ]);
            $contractor->trades()->attach($line->trade_id);
        }

        return $eligible()->inRandomOrder()->limit(self::BIDS_PER_TRADE)->get();
    }
}
