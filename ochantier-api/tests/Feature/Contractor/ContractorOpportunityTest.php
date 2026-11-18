<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Models\Offer;
use App\Models\User;
use App\Support\Promoter\ContractorOffers;

class ContractorOpportunityTest extends ContractorMarketTestCase
{
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
            ->assertJsonPath('data.response', ['can_respond' => true, 'action' => 'Proposer ma prestation de devis', 'reason' => null, 'eligible' => true, 'answered' => false]);

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
        $this->getJson('/api/v1/contractor/opportunities/offers/'.$offer->id)
            ->assertJsonPath('data.deadline.expired', true)
            ->assertJsonPath('data.response.reason', 'Vous avez déjà répondu : consultez votre proposition.');
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
}
