<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Models\Commune;
use App\Models\Contractor;
use App\Models\Offer;
use App\Models\QuoteRequestTrade;
use App\Models\Trade;
use App\Models\User;
use App\Support\Promoter\ContractorOffers;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Facades\DB;

class ContractorOpportunityTest extends ContractorTestCase
{
    private User $promoter;

    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        $this->promoter = User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0712349999']);

        $this->contractor = $this->user->contractor()->create([
            'reference' => 'ENT-10001',
            'kind' => ContractorKind::Independant,
            'name' => 'Yao Serge Koffi',
            'city_id' => $this->city('Abidjan'),
            'status' => ContractorStatus::Actif,
            'verification_status' => ContractorVerificationStatus::Brouillon,
        ]);
        $this->contractor->trades()->attach($this->trade('electricien'));
    }

    public function test_quote_requests_match_the_trades_and_the_zone(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien', 'plombier']);
        $this->quoteRequest('Duplex Bouaké', 'Bouaké', ['electricien']);
        $line = $this->line($site, 'electricien');

        $this->asContractor()->getJson('/api/v1/contractor/opportunities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $line->id)
            ->assertJsonPath('data.0.kind.value', 'quote_request')
            ->assertJsonPath('data.0.trade.code', 'electricien')
            ->assertJsonPath('data.0.site.title', 'Villa Riviera')
            ->assertJsonPath('data.0.site.place', 'Cocody, Abidjan')
            ->assertJsonPath('data.0.deadline.expired', false)
            ->assertJsonPath('data.0.deadline.soon', false)
            ->assertJsonPath('data.0.documents_count', 1)
            ->assertJsonPath('meta.counts', ['quotes' => 1, 'offers' => 0])
            ->assertJsonPath('meta.excluded.quotes', 2)
            ->assertJsonPath('meta.profile.cities.0.name', 'Abidjan')
            ->assertJsonPath('meta.can_respond', false)
            ->assertJsonPath('meta.notice', 'Votre profil n’est pas encore vérifié : vous pouvez consulter les opportunités, mais pas y répondre.');

        $this->contractor->zones()->attach($this->city('Bouaké'));

        $this->getJson('/api/v1/contractor/opportunities')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/contractor/opportunities?search=riviera')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contractor/opportunities?search=Bouaké')->assertJsonCount(1, 'data')->assertJsonPath('data.0.site.title', 'Duplex Bouaké');
        $this->getJson('/api/v1/contractor/opportunities?kind=chantiers')->assertUnprocessable();
    }

    public function test_the_quote_request_detail_hides_the_exact_address_until_the_order(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien', 'plombier']);
        $line = $this->line($site, 'electricien');

        $detail = $this->asContractor()->getJson('/api/v1/contractor/opportunities/quotes/'.$line->id)
            ->assertOk()
            ->assertJsonPath('data.need', 'Villa R+1 : tableau, prises et éclairage.')
            ->assertJsonPath('data.site.description', 'Villa de 5 pièces, gros œuvre achevé.')
            ->assertJsonPath('data.site.constraints', 'Accès par la piste latérale.')
            ->assertJsonPath('data.site.promoter', 'Coordonnées masquées jusqu’à la commande')
            ->assertJsonPath('data.other_trades.0.code', 'plombier')
            ->assertJsonPath('data.site.documents.0.type.code', 'site_plan')
            ->assertJsonPath('data.response.can_respond', false)
            ->assertJsonPath('data.response.reason', 'Vérification du profil requise pour répondre.')
            ->json('data');

        $this->assertArrayNotHasKey('address', $detail['site']);
        $this->assertStringNotContainsString('Rue des Jardins', json_encode($detail));

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->getJson('/api/v1/contractor/opportunities/quotes/'.$line->id)
            ->assertJsonPath('data.response', ['can_respond' => true, 'action' => 'Proposer ma prestation de devis', 'reason' => null, 'eligible' => true]);

        $this->getJson('/api/v1/contractor/opportunities/quotes/'.$this->line($site, 'plombier')->id)
            ->assertOk()
            ->assertJsonPath('data.response.action', 'Non éligible')
            ->assertJsonPath('data.response.reason', 'Cette opportunité ne correspond pas à votre profil : métier non exercé.');

        $this->travel(73)->hours();

        $this->getJson('/api/v1/contractor/opportunities')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/contractor/opportunities/quotes/'.$line->id)
            ->assertJsonPath('data.deadline.expired', true)
            ->assertJsonPath('data.status.value', 'fermee')
            ->assertJsonPath('data.response.reason', 'Le délai de réponse est expiré.');
    }

    public function test_contractual_offers_are_listed_by_zone(): void
    {
        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->publishedContract('Entrepôt Bouaké', 'Bouaké');
        $offer = Offer::query()->where('title', 'Résidence Les Acacias')->sole();

        $this->asContractor()->getJson('/api/v1/contractor/opportunities?kind=offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $offer->id)
            ->assertJsonPath('data.0.kind.value', 'contractor_offer')
            ->assertJsonPath('data.0.amount', 3300000)
            ->assertJsonPath('data.0.period.days', 30)
            ->assertJsonPath('data.0.trades', [])
            ->assertJsonPath('data.0.my_response', null)
            ->assertJsonPath('meta.counts', ['quotes' => 0, 'offers' => 1])
            ->assertJsonPath('meta.excluded.offers', 1);

        $this->getJson('/api/v1/contractor/opportunities/offers/'.$offer->id)
            ->assertOk()
            ->assertJsonPath('data.payment_mode.value', 'par_etape')
            ->assertJsonPath('data.quote_document.type.code', 'site_quote')
            ->assertJsonPath('data.site.photos.0.url', fn ($url) => is_string($url))
            ->assertJsonPath('data.response.can_respond', true)
            ->assertJsonPath('data.response.action', 'Répondre à l’offre');

        $this->assertNotEmpty($this->getJson('/api/v1/contractor/opportunities/offers/'.$offer->id)->json('data.milestones'));

        app(ContractorOffers::class)->addBid($offer, $this->contractor, 3000000, 30, 6);
        $this->getJson('/api/v1/contractor/opportunities?kind=offers')
            ->assertJsonPath('data.0.my_response.amount', 3000000)
            ->assertJsonPath('data.0.my_response.status.value', 'proposee');

        $this->travel(73)->hours();
        $this->getJson('/api/v1/contractor/opportunities?kind=offers')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/contractor/opportunities/offers/'.$offer->id)->assertJsonPath('data.response.reason', 'Le délai de réponse est expiré.');
    }

    public function test_a_contractual_offer_after_a_quote_request_targets_its_trades(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['plombier']);
        $offer = $this->contractOn($site);

        $this->asContractor()->getJson('/api/v1/contractor/opportunities?kind=offers')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/contractor/opportunities/offers/'.$offer->id)
            ->assertJsonPath('data.trades.0.code', 'plombier')
            ->assertJsonPath('data.response.reason', 'Cette opportunité ne correspond pas à votre profil : métier non exercé.');

        $this->contractor->trades()->attach($this->trade('plombier'));
        $this->getJson('/api/v1/contractor/opportunities?kind=offers')->assertJsonCount(1, 'data');
    }

    public function test_unknown_opportunities_and_missing_profiles_are_not_found(): void
    {
        $this->asContractor()->getJson('/api/v1/contractor/opportunities/quotes/'.fake()->uuid())->assertNotFound();
        $this->getJson('/api/v1/contractor/opportunities/offers/not-a-uuid')->assertNotFound();

        $this->actingAsContractor(User::factory()->contractor()->create());
        $this->getJson('/api/v1/contractor/opportunities')->assertNotFound();
    }

    /**
     * @return $this
     */
    private function asContractor(): static
    {
        return $this->actingAsContractor($this->user);
    }

    /**
     * @return $this
     */
    private function asPromoter(): static
    {
        return $this->actingAsContractor($this->promoter);
    }

    /**
     * Chantier du promoteur, prêt à publier, dans la commune donnée.
     *
     * @return array<string, mixed>
     */
    private function site(string $title, string $commune): array
    {
        $site = $this->asPromoter()->postJson('/api/v1/promoter/sites', ['title' => $title])->assertCreated()->json('data');
        $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image()], ['Accept' => 'application/json'])->assertOk();
        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'address' => '12 Rue des Jardins',
            'commune_id' => Commune::query()->where('name', $commune)->value('id'),
            'description' => 'Villa de 5 pièces, gros œuvre achevé.',
            'access_instructions' => 'Accès par la piste latérale.',
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'planned_end_date' => now()->addDays(34)->toDateString(),
        ])->assertOk();
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_plan', 'file' => $this->pdf('Plan.pdf')], ['Accept' => 'application/json'])->assertCreated();

        return $site;
    }

    /**
     * Demande de devis envoyée (« Rechercher un technicien »).
     *
     * @param  list<string>  $trades
     * @return array<string, mixed>
     */
    private function quoteRequest(string $title, string $commune, array $trades): array
    {
        $site = $this->site($title, $commune);
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => false, 'quote_request' => [
            'trade_ids' => array_map(fn (string $code) => Trade::query()->where('code', $code)->value('id'), $trades),
            'notes' => 'Villa R+1 : tableau, prises et éclairage.',
        ]]])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        return $site;
    }

    /**
     * Offre contractuelle publiée avec le devis du promoteur.
     */
    private function publishedContract(string $title, string $commune): Offer
    {
        return $this->contractOn($this->site($title, $commune));
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function contractOn(array $site): Offer
    {
        DB::table('quote_requests')->where('site_id', $site['id'])->update(['status' => 'recu']);
        DB::table('sites')->where('id', $site['id'])->update(['status' => 'brouillon']);

        $this->asPromoter()->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_quote', 'file' => $this->pdf('Devis.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->putJson("/api/v1/promoter/sites/{$site['id']}/offer", ['type' => 'contractuelle', 'contract' => ['has_quote' => true, 'amount' => 3300000, 'payment_mode' => 'par_etape']])->assertOk();
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/publish")->assertOk();

        return Offer::query()->where('site_id', $site['id'])->sole();
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function line(array $site, string $trade): QuoteRequestTrade
    {
        return QuoteRequestTrade::query()->whereHas('quoteRequest', fn ($query) => $query->where('site_id', $site['id']))->whereHas('trade', fn ($query) => $query->where('code', $trade))->sole();
    }
}
