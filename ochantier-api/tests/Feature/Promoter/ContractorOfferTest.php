<?php

namespace Tests\Feature\Promoter;

use App\Enums\ContractorBidStatus;
use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\OfferStatus;
use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\SiteStatus;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Mission;
use App\Models\Offer;
use App\Models\PaymentSchedule;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\ContractorOffers;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContractorOfferTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        Storage::fake(config('filesystems.media'));
    }

    public function test_the_promoter_chooses_a_contractor_and_pays_the_deposit(): void
    {
        $site = $this->publishedContract('par_etape');
        $offer = Offer::query()->sole();

        $this->assertSame(OfferStatus::Publiee, $offer->status);
        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.status.value', 'recherche')
            ->assertJsonPath('data.display_status', ['value' => 'recherche_entrepreneurs', 'label' => 'Recherche d’entrepreneurs en cours'])
            ->assertJsonPath('data.contractor_offers.offers', []);

        // Note 40, montant 30, durée 15, distance 15 (distance inconnue : valeur neutre pour tous).
        $good = $this->bid($offer, 'BTP Lagune', 4.8, 3000000);              // 38 + 5 + 15 + 7,5 = 65,5
        $cheaper = $this->bid($offer, 'Ivoire Bâtisseurs', 4.8, 2800000);    // 38 + 10 + 15 + 7,5 = 70,5
        $hidden = $this->bid($offer, 'Konan Construction', 4.9, 3200000);    // 39 + 0 + 15 + 7,5 = 61,5
        $best = $this->bid($offer, 'Petit Chantier', 4.0, 2000000);          // 30 + 30 + 15 + 7,5 = 82,5
        $prices = app(PricingRules::class)->breakdown(3000000);

        $this->getJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers")
            ->assertOk()
            ->assertJsonPath('data.offers.*.id', [$best->id, $cheaper->id, $good->id])
            ->assertJsonPath('data.offers.*.score', [82.5, 70.5, 65.5])
            ->assertJsonPath('data.offers.*.is_best', [true, false, false])
            ->assertJsonPath('data.offers.2.contractor.name', 'BTP Lagune')
            ->assertJsonPath('data.offers.2.contractor.completed_sites', 20)
            ->assertJsonPath('data.offers.2.amount', $prices['total'])
            ->assertJsonPath('data.offers.2.team_size', 8)
            ->assertJsonPath('data.offers.2.duration_days', 30)
            ->assertJsonPath('data.can_pay', false);
        $this->getJson('/api/v1/promoter/sites')
            ->assertJsonPath('data.0.display_status.value', 'offres_entrepreneurs')
            ->assertJsonPath('data.0.contractor_offers.action', ['value' => 'choisir_entrepreneur', 'label' => 'Choisir une offre']);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers/{$hidden->id}/choose")
            ->assertJsonValidationErrors(['contractor_bid' => 'Cette offre n’est plus proposée.']);
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers/{$cheaper->id}/choose")->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers/{$good->id}/choose")
            ->assertOk()
            ->assertJsonPath('data.chosen_id', $good->id)
            ->assertJsonPath('data.deposit_amount', $prices['deposit'])
            ->assertJsonPath('data.can_pay', true);

        $this->assertSame(ContractorBidStatus::Proposee, $cheaper->refresh()->status);
        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.status.value', 'paiement')
            ->assertJsonPath('data.display_status', ['value' => 'offre_choisie', 'label' => 'Offre choisie · acompte à payer'])
            ->assertJsonPath('data.contractor_offers.chosen_id', $good->id);
        $this->getJson('/api/v1/promoter/payments')
            ->assertJsonFragment(['target' => ['contractor_bid_id' => $good->id]]);

        $this->postJson('/api/v1/promoter/payments', $this->payment($cheaper->id))
            ->assertJsonValidationErrors(['contractor_bid_id' => 'Choisissez d’abord cette offre.']);
        $this->postJson('/api/v1/promoter/payments/quote', ['contractor_bid_id' => $good->id, 'operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.amount', $prices['deposit'])
            ->assertJsonPath('data.lines.0', ['label' => 'Offre de BTP Lagune (30 jours)', 'amount' => 3000000]);
        $this->postJson('/api/v1/promoter/payments', $this->payment($good->id), ['Idempotency-Key' => 'acompte-entrepreneur'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie')
            ->assertJsonPath('data.amount', $prices['deposit']);

        $this->assertSame(SiteStatus::Confirme, Site::query()->sole()->status);
        $this->assertSame(ContractorBidStatus::Retenue, $good->refresh()->status);
        $this->assertSame(ContractorBidStatus::NonRetenue, $best->refresh()->status);
        $offer->refresh();
        $this->assertSame(OfferStatus::Pourvue, $offer->status);
        $this->assertSame($good->id, $offer->contractor_bid_id);

        $mission = Mission::query()->with('need.trade')->sole();
        $this->assertSame('forfait_entrepreneur', $mission->need->trade->code);
        $this->assertSame('BTP Lagune', $mission->need->role_label);
        $this->assertSame(30, $mission->planned_days);

        $schedules = PaymentSchedule::query()->orderBy('schedule_type')->orderBy('sequence')->get();
        $deposit = $schedules->firstWhere('schedule_type', PaymentScheduleType::Acompte);
        $steps = $schedules->where('schedule_type', PaymentScheduleType::Jalon);
        $this->assertSame(PaymentScheduleStatus::Payee, $deposit->status);
        $this->assertCount(3, $steps);
        $this->assertSame($prices['balance'], (int) $steps->sum('amount_due'));
        $this->assertSame($prices['deposit'], (int) $steps->sum('deposit_deduction_amount'));
        $this->assertSame($prices['total'], (int) $offer->milestones()->sum('amount'));
    }

    public function test_a_shorter_and_closer_offer_ranks_first(): void
    {
        $site = $this->publishedContract('hebdomadaire', ['latitude' => 5.3600, 'longitude' => -3.9800]);
        $offer = Offer::query()->sole();

        $far = $this->bid($offer, 'Loin', 4.5, 3000000, location: ['latitude' => 5.4500, 'longitude' => -3.9800]);
        $long = $this->bid($offer, 'Lent', 4.5, 3000000, days: 45, location: ['latitude' => 5.3600, 'longitude' => -3.9800]);
        $best = $this->bid($offer, 'Proche et rapide', 4.5, 3000000, location: ['latitude' => 5.3600, 'longitude' => -3.9800]);

        // Note 4,5 : 35 points ; même montant : 30 points chacun. « Loin » perd la distance, « Lent » la durée :
        // à égalité, la première offre reçue passe devant.
        $this->getJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers")
            ->assertJsonPath('data.offers.*.id', [$best->id, $far->id, $long->id])
            ->assertJsonPath('data.offers.*.score', [95, 80, 80])
            ->assertJsonPath('data.offers.0.distance_km', 0);

        $this->assertEqualsWithDelta(10.0, $far->distance_km, 0.5);
    }

    public function test_the_weights_can_be_changed(): void
    {
        Setting::query()->create(['key' => 'contractors.ranking_weights', 'value' => ['note' => 10, 'montant' => 70, 'duree' => 10, 'distance' => 10]]);
        $site = $this->publishedContract('hebdomadaire');
        $offer = Offer::query()->sole();

        $rated = $this->bid($offer, 'Bien noté', 5.0, 3000000);
        $cheap = $this->bid($offer, 'Moins cher', 3.0, 2000000);

        $this->getJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers")
            ->assertJsonPath('data.offers.*.id', [$cheap->id, $rated->id]);
    }

    public function test_a_weekly_balance_is_split_by_week_of_work(): void
    {
        $site = $this->publishedContract('hebdomadaire');
        $bid = $this->bid(Offer::query()->sole(), 'BTP Lagune', 4.8, 1400000, days: 14);

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers/{$bid->id}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', $this->payment($bid->id))->assertCreated();

        $weeks = PaymentSchedule::query()->where('schedule_type', PaymentScheduleType::Hebdomadaire)->orderBy('sequence')->get();
        $prices = app(PricingRules::class)->breakdown(1400000);
        $this->assertCount(2, $weeks);
        $this->assertSame($prices['balance'], (int) $weeks->sum('amount_due'));
        $this->getJson('/api/v1/promoter/payments')->assertJsonFragment(['who' => 'BTP Lagune']);
    }

    public function test_the_simulator_sends_contractor_offers(): void
    {
        $site = $this->publishedContract('hebdomadaire');

        $this->artisan('ochantier:simulate-contractors')->assertSuccessful();

        $this->assertSame(4, ContractorBid::query()->count());
        $this->getJson("/api/v1/promoter/sites/{$site['id']}/contractor-offers")->assertJsonCount(3, 'data.offers');
    }

    /**
     * Offre contractuelle avec le devis du promoteur, publiée.
     *
     * @return array<string, mixed>
     */
    private function publishedContract(string $mode, ?array $location = null): array
    {
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Résidence Les Acacias'])->assertCreated()->json('data');
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => 'Angré, Abidjan',
            'commune_id' => $this->commune(),
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(34)->toDateString(),
            ...($location ? ['location' => $location] : []),
        ])->assertOk();
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_quote', 'file' => $this->pdf('Devis.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3300000, 'payment_mode' => $mode]])->assertOk();

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertOk()
            ->assertJsonPath('message', 'Chantier publié — recherche d’entrepreneurs lancée');

        return $site;
    }

    /**
     * @param  array{latitude: float, longitude: float}|null  $location
     */
    private function bid(Offer $offer, string $name, float $rating, int $amount, int $days = 30, ?array $location = null): ContractorBid
    {
        $contractor = Contractor::query()->create([
            'reference' => sprintf('ENT-%05d', DB::scalar("SELECT nextval('contractor_reference_seq')")),
            'kind' => ContractorKind::Entreprise,
            'name' => $name,
            'rating_avg' => $rating,
            'rating_count' => 20,
            'completed_sites_count' => 20,
            'team_size' => 8,
            'location' => $location,
            'status' => ContractorStatus::Actif,
        ]);

        return app(ContractorOffers::class)->addBid($offer, $contractor, $amount, $days, 8);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(string $bid): array
    {
        return ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'];
    }
}
