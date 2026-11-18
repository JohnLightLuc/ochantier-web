<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorBidStatus;
use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\User;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\ContractorOffers;
use Illuminate\Testing\TestResponse;

class ContractorOfferResponseTest extends ContractorMarketTestCase
{
    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->asContractor();
    }

    public function test_the_offer_shows_the_promoter_terms_with_the_platform_schedule(): void
    {
        $net = app(ContractorOffers::class)->conformingAmount($this->offer);

        $terms = $this->asContractor()->getJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}")
            ->assertOk()
            ->assertJsonPath('data.terms.amount', $net)
            ->assertJsonPath('data.terms.promoter_amount', 3300000)
            ->assertJsonPath('data.terms.duration_days', 30)
            ->assertJsonPath('data.terms.schedule.0.label', 'Acompte à l’attribution')
            ->assertJsonPath('data.terms.schedule.0.percent', 30)
            ->assertJsonPath('data.response.action', 'Répondre à l’offre')
            ->json('data.terms');

        $this->assertSame($net, array_sum(array_column($terms['schedule'], 'amount')));
        $this->assertSame(['Acompte à l’attribution', 'Étape 1/3', 'Étape 2/3', 'Étape 3/3'], array_column($terms['schedule'], 'label'));
    }

    public function test_a_conforming_response_reuses_the_promoter_terms(): void
    {
        $net = app(ContractorOffers::class)->conformingAmount($this->offer);

        $this->respond(['mode' => 'conforme', 'amount' => 1000, 'team_size' => 6])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Une offre conforme reprend les conditions du promoteur : passez en contre-proposition pour les modifier.');

        $response = $this->respond(['mode' => 'conforme', 'team_size' => 6, 'message' => 'Équipe disponible dès lundi.'])
            ->assertCreated()
            ->assertJsonPath('message', 'Offre envoyée au promoteur.')
            ->assertJsonPath('data.response_mode.value', 'conforme')
            ->assertJsonPath('data.amount', $net)
            ->assertJsonPath('data.conditions.duration_days', 30)
            ->assertJsonPath('data.comparison.amount_difference', 0)
            ->assertJsonPath('data.money.promoter_amount', app(PricingRules::class)->breakdown($net)['total'])
            ->assertJsonPath('data.display_status', ['value' => 'en_attente', 'label' => 'En attente'])
            ->assertJsonPath('data.tracking.1.state', 'current')
            ->json('data');

        $this->respond(['mode' => 'conforme', 'team_size' => 6])
            ->assertUnprocessable()
            ->assertJsonPath('errors.response.0', 'Vous avez déjà répondu : consultez votre proposition.');
        $this->getJson('/api/v1/contractor/opportunities?kind=offers')->assertJsonPath('data.0.my_response.id', $response['id']);

        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$this->offer->site_id}/contractor-offers")
            ->assertJsonPath('data.offers.0.contractor.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.offers.0.response_mode.value', 'conforme')
            ->assertJsonPath('data.offers.0.team_size', 6)
            ->assertJsonPath('data.offers.0.message', 'Équipe disponible dès lundi.');
    }

    public function test_a_counter_proposal_can_be_drafted_then_sent(): void
    {
        $start = now()->addDays(10)->toDateString();
        $end = now()->addDays(49)->toDateString();

        $this->respond(['mode' => 'contre_proposition', 'amount' => 0, 'start_date' => $end, 'end_date' => $start, 'team_size' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Le prix proposé doit être positif.')
            ->assertJsonPath('errors.end_date.0', 'La date de fin doit être postérieure au début.')
            ->assertJsonPath('errors.team_size.0', 'Indiquez la taille de votre équipe.');

        $draft = $this->respond(['mode' => 'contre_proposition', 'amount' => 2800000, 'start_date' => $start, 'end_date' => $end, 'team_size' => 8, 'draft' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'Brouillon enregistré.')
            ->assertJsonPath('data.status.value', 'brouillon')
            ->assertJsonPath('data.conditions.duration_days', 40)
            ->assertJsonPath('data.tracking.0.state', 'current')
            ->assertJsonPath('data.actions.delete_draft', true)
            ->json('data.id');

        $this->getJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}")
            ->assertJsonPath('data.response.action', 'Reprendre mon brouillon')
            ->assertJsonPath('data.draft_id', $draft);
        $this->getJson('/api/v1/contractor/my-offers?tab=brouillons')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.tabs.0', ['key' => 'brouillons', 'label' => 'Brouillons', 'count' => 1]);
        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$this->offer->site_id}/contractor-offers")->assertJsonPath('data.offers', []);

        $this->asContractor()->respond(['mode' => 'contre_proposition', 'amount' => 2900000, 'start_date' => $start, 'end_date' => $end, 'team_size' => 8])
            ->assertCreated()
            ->assertJsonPath('data.id', $draft)
            ->assertJsonPath('data.status.value', 'proposee')
            ->assertJsonPath('data.amount', 2900000)
            ->assertJsonPath('data.comparison.duration_difference_days', 10);
        $this->deleteJson("/api/v1/contractor/offer-responses/{$draft}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.response.0', 'Seul un brouillon peut être supprimé.');
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $draft = $this->asContractor()->respond(['mode' => 'conforme', 'team_size' => 4, 'draft' => true])->json('data.id');

        $this->deleteJson("/api/v1/contractor/offer-responses/{$draft}")->assertOk()->assertJsonPath('message', 'Brouillon supprimé.');
        $this->getJson("/api/v1/contractor/offer-responses/{$draft}")->assertNotFound();
    }

    public function test_the_chosen_response_is_attributed_once_the_deposit_is_paid(): void
    {
        $start = now()->addDays(10)->toDateString();
        $mine = $this->asContractor()->respond(['mode' => 'contre_proposition', 'amount' => 2900000, 'start_date' => $start, 'end_date' => now()->addDays(39)->toDateString(), 'team_size' => 8])->json('data.id');
        $other = $this->otherResponse(3100000);
        $abandoned = $this->otherResponse(3000000, draft: true);

        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->offer->site_id}/contractor-offers/{$mine}/choose")->assertOk();
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'offer.chosen')->exists());
        $this->asContractor()->getJson("/api/v1/contractor/offer-responses/{$mine}")
            ->assertJsonPath('data.display_status', ['value' => 'acompte_en_attente', 'label' => 'Retenue · acompte en attente'])
            ->assertJsonPath('data.tracking.2.state', 'current')
            ->assertJsonPath('data.attribution', null);

        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $mine, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-reponse'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'reussie');

        $this->asContractor()->getJson("/api/v1/contractor/offer-responses/{$mine}")
            ->assertJsonPath('data.display_status', ['value' => 'attribuee', 'label' => 'Attribuée'])
            ->assertJsonPath('data.tracking.3.state', 'done')
            ->assertJsonPath('data.attribution.site_id', $this->offer->site_id)
            ->assertJsonPath('data.attribution.start_date', $start)
            ->assertJsonPath('data.attribution.address', '12 Rue des Jardins')
            ->assertJsonPath('data.attribution.promoter.name', 'Awa Kouamé')
            ->assertJsonPath('data.actions.open_site', true);

        $this->assertSame($start, Mission::query()->sole()->planned_start_date->toDateString());
        $this->assertSame(ContractorBidStatus::NonRetenue, $other->refresh()->status);
        $this->assertSame(ContractorBidStatus::Annulee, $abandoned->refresh()->status);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'offer.attributed')->exists());
        $this->assertTrue(Notification::query()->where('recipient_id', $other->contractor->user_id)->where('event_code', 'offer.not_retained')->exists());

        $this->getJson('/api/v1/contractor/my-offers?tab=acceptees')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    }

    public function test_my_offers_gathers_responses_and_quote_proposals(): void
    {
        $line = $this->line($this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']), 'electricien');
        $this->asContractor()->postJson("/api/v1/contractor/opportunities/quotes/{$line->id}/proposal", ['fee_amount' => 25000, 'visit_date' => now()->addDays(3)->toDateString(), 'visit_slot' => '10:00 – 12:00'])->assertCreated();
        $this->respond(['mode' => 'conforme', 'team_size' => 5])->assertCreated();

        $this->getJson('/api/v1/contractor/my-offers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.tabs.1', ['key' => 'en_attente', 'label' => 'En attente', 'count' => 2]);
        $this->assertEqualsCanonicalizing(['offer_response', 'quote_proposal'], array_column(array_column($this->getJson('/api/v1/contractor/my-offers')->json('data'), 'kind'), 'value'));
        $this->getJson('/api/v1/contractor/my-offers?search=riviera')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contractor/my-offers?tab=inconnu')->assertUnprocessable();
    }

    public function test_an_unverified_contractor_cannot_respond(): void
    {
        $this->contractor->update(['verification_status' => ContractorVerificationStatus::ACorriger]);

        $this->asContractor()->respond(['mode' => 'conforme', 'team_size' => 5])
            ->assertUnprocessable()
            ->assertJsonPath('errors.response.0', 'Vérification du profil requise pour répondre.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): TestResponse
    {
        return $this->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", $data);
    }

    private function otherResponse(int $amount, bool $draft = false): ContractorBid
    {
        $contractor = User::factory()->contractor()->create()->contractor()->create([
            'reference' => 'ENT-'.random_int(20000, 99999),
            'kind' => ContractorKind::Entreprise,
            'name' => 'Entreprise '.$amount,
            'city_id' => $this->city(),
            'status' => ContractorStatus::Actif,
            'verification_status' => ContractorVerificationStatus::Valide,
        ]);

        return app(ContractorOffers::class)->addBid($this->offer, Contractor::query()->findOrFail($contractor->id), $amount, 30, 6, draft: $draft);
    }
}
