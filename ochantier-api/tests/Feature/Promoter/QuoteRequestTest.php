<?php

namespace Tests\Feature\Promoter;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\QuoteBidStatus;
use App\Enums\SiteStatus;
use App\Models\City;
use App\Models\Contractor;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\PaymentTransaction;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestTrade;
use App\Models\Site;
use App\Models\User;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\QuoteSimulator;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QuoteRequestTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        Storage::fake(config('filesystems.media'));
    }

    public function test_without_a_quote_the_promoter_orders_one_per_trade_then_defines_the_offer(): void
    {
        $site = $this->quoteRequest(['macon', 'plombier']);

        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.status.value', 'devis')
            ->assertJsonPath('data.display_status', ['value' => 'devis_recherche', 'label' => 'Recherche de technicien pour devis'])
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.quote_request.status.value', 'recherche')
            ->assertJsonPath('data.quote_request.notes', 'Villa R+1')
            ->assertJsonPath('data.quote_request.trades.*.trade.code', ['macon', 'plombier'])
            ->assertJsonPath('data.quote_request.ready_to_order', false);
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['title' => 'Autre titre'])
            ->assertJsonValidationErrors(['site' => 'Une demande de devis est en cours : le chantier ne peut pas être modifié avant la réception des devis.']);

        $macon = $this->line($site, 'macon');
        $plombier = $this->line($site, 'plombier');
        $cheap = $this->bid($macon, 'Yao Koffi', 15000, 4.8);
        $best = $this->bid($macon, 'Kouamé Koffi', 25000, 4.9);
        $sameRating = $this->bid($macon, 'Bamba Seydou', 18000, 4.8);
        $hidden = $this->bid($macon, 'Traoré Ali', 10000, 4.0);
        $plumber = $this->bid($plombier, 'Awa Traoré', 20000, 4.5);

        $this->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")
            ->assertOk()
            ->assertJsonPath('data.trades.0.offers.*.id', [$best->id, $cheap->id, $sameRating->id])
            ->assertJsonPath('data.trades.0.offers.*.is_best', [true, false, false])
            ->assertJsonPath('data.trades.0.offers.0.technician.name', 'Kouamé Koffi')
            ->assertJsonPath('data.trades.0.offers.0.technician.experience_years', 5)
            ->assertJsonPath('data.trades.0.offers.0.amount', 27500)
            ->assertJsonPath('data.trades.0.offers.0.visit_slot', '10:00 – 12:00')
            ->assertJsonPath('data.trades.0.offers.0.technician.kind.value', 'independant')
            ->assertJsonPath('data.offers_count', 4)
            ->assertJsonPath('data.ready_to_order', true);
        $this->getJson('/api/v1/promoter/sites')->assertJsonPath('data.0.quote_request.action', ['value' => 'choisir_devis', 'label' => 'Choisir les offres de devis']);

        $this->postJson('/api/v1/promoter/payments', $this->payment([$best->id]))
            ->assertJsonValidationErrors(['quote_bid_ids' => 'Choisissez une offre pour « Plombier ».']);
        $this->postJson('/api/v1/promoter/payments', $this->payment([$hidden->id, $plumber->id]))
            ->assertJsonValidationErrors(['quote_bid_ids' => 'Cette offre n’est plus proposée pour « Maçon ».']);

        $this->postJson('/api/v1/promoter/payments/quote', ['quote_bid_ids' => [$best->id, $plumber->id], 'operator_code' => 'orange_money'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'commande_devis')
            ->assertJsonPath('data.amount', 49500)
            ->assertJsonPath('data.lines.0', ['label' => 'Devis maçon', 'amount' => 27500]);
        $this->postJson('/api/v1/promoter/payments', $this->payment([$best->id, $plumber->id]), ['Idempotency-Key' => 'devis-1'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie');

        $this->assertSame(PaymentTransactionPurpose::CommandeDevis, PaymentTransaction::query()->sole()->purpose);
        $this->assertSame(QuoteBidStatus::NonRetenue, $cheap->refresh()->status);
        $this->assertSame(QuoteBidStatus::Choisie, $best->refresh()->status);
        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.display_status', ['value' => 'devis_commande', 'label' => 'Devis commandé'])
            ->assertJsonPath('data.quote_request.total_amount', 49500)
            ->assertJsonPath('data.quote_request.trades.0.visit.status.value', 'planifiee')
            ->assertJsonPath('data.quote_request.trades.0.selected.technician.name', 'Kouamé Koffi')
            ->assertJsonPath('data.quote_request.trades.0.offers', []);
        $this->deleteJson("/api/v1/promoter/sites/{$site['id']}/quote-request")
            ->assertJsonValidationErrors(['quote_request' => 'Seule une demande de devis en recherche peut être annulée.']);

        app(QuoteSimulator::class)->complete(QuoteRequest::query()->sole());

        $detail = $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.status.value', 'brouillon')
            ->assertJsonPath('data.display_status', ['value' => 'devis_recu', 'label' => 'Devis reçu · offre à définir'])
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.offer.draft.has_quote', true)
            ->assertJsonCount(2, 'data.offer.draft.quotes')
            ->assertJsonPath('data.quote_request.status.value', 'recu')
            ->assertJsonPath('data.quote_request.trades.0.quote.status', ['value' => 'effectue', 'label' => 'Effectué'])
            ->assertJsonPath('data.quote_request.trades.0.quote.technician.name', 'Kouamé Koffi')
            ->assertJsonPath('data.quote_request.trades.0.quote.site.trade', 'Maçon')
            ->assertJsonPath('data.documents.0.type.code', 'site_quote')
            ->json('data');
        $this->assertCount(3, $detail['quote_request']['trades'][0]['quote']['items']);
        $this->assertSame(array_sum(array_column($detail['quote_request']['trades'][0]['quote']['items'], 'total')), $detail['quote_request']['trades'][0]['quote']['total_amount']);
        $this->assertStringStartsWith('DE-', $detail['quote_request']['trades'][0]['quote']['number']);

        $this->getJson('/api/v1/promoter/sites?filter=recherche')->assertJsonPath('data.0.quote_request.action', ['value' => 'definir_offre', 'label' => 'Définir l’offre financière']);
        $this->getJson('/api/v1/promoter/sites?filter=brouillons')->assertJsonCount(0, 'data')->assertJsonPath('meta.counts.recherche', 1);
        $this->deleteJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonValidationErrors(['site' => 'Des devis ont été commandés pour ce chantier : il ne peut plus être supprimé.']);

        // Il ne reste qu’à saisir l’offre financière et à publier.
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['planned_start_date' => now()->addDays(5)->toDateString(), 'planned_end_date' => now()->addDays(34)->toDateString()])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3000000, 'payment_mode' => 'hebdomadaire']])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.offer.published.type.value', 'contractuelle');

        $this->assertContains(Offer::query()->sole()->quote_document_id, QuoteRequestTrade::query()->pluck('quote_document_id')->all());
    }

    public function test_the_promoter_is_notified_once_every_trade_has_an_offer(): void
    {
        $site = $this->quoteRequest(['macon', 'peintre']);
        $received = fn () => Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'quote.offers_received');

        $this->bid($this->line($site, 'macon'), 'Koné Bâtiment', 20000, 4.5);
        $this->assertFalse($received()->exists());

        $this->bid($this->line($site, 'peintre'), 'Déco Plus', 15000, 4.2);
        $this->bid($this->line($site, 'macon'), 'Traoré & Fils', 18000, 4.8);

        $notification = $received()->sole();
        $this->assertSame('Offres de devis reçues', $notification->title);
        $this->assertSame('Villa Riviera : chaque corps de métier a reçu au moins une offre. Choisissez une offre par métier pour commander les devis.', $notification->body);
        $this->assertEquals(['type' => 'quote_request', 'id' => $site['id']], $notification->data['target']);
        $this->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")->assertJsonPath('data.ready_to_order', true);
    }

    public function test_a_quote_request_can_be_cancelled_before_the_order(): void
    {
        $site = $this->quoteRequest(['macon']);

        $this->deleteJson("/api/v1/promoter/sites/{$site['id']}/quote-request")->assertOk();

        $this->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertJsonPath('data.status.value', 'brouillon')
            ->assertJsonPath('data.display_status.value', 'brouillon')
            ->assertJsonPath('data.quote_request', null)
            ->assertJsonPath('data.can_delete', true);

        // Une nouvelle demande peut être envoyée.
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();
        $this->assertSame(SiteStatus::Devis, Site::query()->sole()->status);
    }

    public function test_the_simulator_sends_technician_offers(): void
    {
        $site = $this->quoteRequest(['macon']);

        $this->artisan('ochantier:simulate-quotes')->assertSuccessful();

        $this->assertSame(4, QuoteBid::query()->whereNotNull('contractor_id')->count());
        $this->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")
            ->assertJsonCount(3, 'data.trades.0.offers')
            ->assertJsonPath('data.ready_to_order', true);
    }

    /**
     * Brouillon d’offre contractuelle sans devis, envoyé (« Rechercher un technicien »).
     *
     * @param  list<string>  $trades
     * @return array<string, mixed>
     */
    /**
     * Technicien actif d’Abidjan, maçon, avec son compte (destinataire des notifications).
     */
    private function abidjanMason(): Contractor
    {
        $contractor = Contractor::query()->create([
            'reference' => sprintf('ENT-%05d', DB::scalar("SELECT nextval('contractor_reference_seq')")),
            'kind' => ContractorKind::Independant,
            'name' => 'Koné Bâtiment',
            'user_id' => User::factory()->contractor()->create()->id,
            'city_id' => City::query()->where('name', 'Abidjan')->value('id'),
            'status' => ContractorStatus::Actif,
            'verification_status' => ContractorVerificationStatus::Valide,
        ]);
        $contractor->trades()->attach($this->trade('macon')->id);

        return $contractor;
    }

    /**
     * Demande de devis d’un chantier localisé par GPS à Bingerville, sans commune envoyée par l’application.
     *
     * @return array<string, mixed>
     */
    private function quoteRequestFromGps(): array
    {
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Bingerville'])->assertCreated()->json('data');
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['address' => 'Blachon, Bingerville', 'location' => ['latitude' => 5.357129, 'longitude' => -3.876571]])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => ['trade_ids' => [$this->trade('macon')->id]]]])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        return $site;
    }

    public function test_the_commune_comes_from_the_gps_position_and_the_professionals_of_the_city_are_notified(): void
    {
        $contractor = $this->abidjanMason();

        $site = $this->quoteRequestFromGps();

        $this->assertSame('Bingerville', Site::query()->find($site['id'])->commune->name);
        $this->assertTrue(Notification::query()->where('recipient_id', $contractor->user_id)->where('event_code', 'opportunity.quote')->exists());
    }

    public function test_a_site_without_commune_nor_gps_cannot_be_published(): void
    {
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa sans commune'])->assertCreated()->json('data');
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['address' => 'Quelque part'])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => ['trade_ids' => [$this->trade('macon')->id]]]])->assertOk();

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['publication' => 'Indiquez la commune du chantier']);
    }

    public function test_the_command_fills_the_missing_commune_and_notifies_the_professionals(): void
    {
        $contractor = $this->abidjanMason();
        $site = $this->quoteRequestFromGps();
        // Chantier publié avant la correction : sans commune, personne n’a été prévenu.
        Site::query()->whereKey($site['id'])->update(['commune_id' => null]);
        Notification::query()->where('event_code', 'opportunity.quote')->delete();

        $this->artisan('ochantier:fill-site-communes')->assertSuccessful();
        $this->assertNull(Site::query()->find($site['id'])->commune_id);
        $this->assertFalse(Notification::query()->where('event_code', 'opportunity.quote')->exists());

        $this->artisan('ochantier:fill-site-communes', ['--apply' => true])->assertSuccessful();
        $this->artisan('ochantier:fill-site-communes', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Bingerville', Site::query()->find($site['id'])->commune->name);
        $this->assertSame(1, Notification::query()->where('recipient_id', $contractor->user_id)->where('event_code', 'opportunity.quote')->count());
    }

    private function quoteRequest(array $trades): array
    {
        $site = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->assertCreated()->json('data');
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['address' => 'Riviera 3, Abidjan', 'commune_id' => $this->commune()])->assertOk();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => [
            'trade_ids' => array_map(fn (string $code) => $this->trade($code)->id, $trades),
            'notes' => 'Villa R+1',
        ]]])->assertOk();

        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")
            ->assertOk()
            ->assertJsonPath('message', 'Demande de devis envoyée — recherche de technicien lancée');

        return $site;
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function line(array $site, string $trade): QuoteRequestTrade
    {
        return QuoteRequestTrade::query()->whereHas('quoteRequest', fn ($query) => $query->where('site_id', $site['id']))->where('trade_id', $this->trade($trade)->id)->sole();
    }

    /**
     * Proposition d’un entrepreneur vérifié du métier : $fee de frais de visite, plus 10 % de frais de service.
     */
    private function bid(QuoteRequestTrade $line, string $name, int $fee, float $rating): QuoteBid
    {
        $contractor = Contractor::query()->create([
            'reference' => sprintf('ENT-%05d', DB::scalar("SELECT nextval('contractor_reference_seq')")),
            'kind' => ContractorKind::Independant,
            'name' => $name,
            'experience_years' => 5,
            'rating_avg' => $rating,
            'status' => ContractorStatus::Actif,
            'verification_status' => ContractorVerificationStatus::Valide,
        ]);

        return app(QuoteRequests::class)->addBid($line, $contractor, $fee, CarbonImmutable::today()->addDays(3), '10:00 – 12:00', '2 h');
    }

    /**
     * @param  list<string>  $bids
     * @return array<string, mixed>
     */
    private function payment(array $bids): array
    {
        return ['quote_bid_ids' => $bids, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'];
    }
}
