<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\QuoteBidStatus;
use App\Enums\QuoteRequestStatus;
use App\Enums\SiteStatus;
use App\Models\Contractor;
use App\Models\Notification;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\QuoteVersion;
use App\Models\Site;
use App\Models\User;
use App\Support\Promoter\QuoteRequests;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

class ContractorQuoteProposalTest extends ContractorMarketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide, 'experience_years' => 8]);
    }

    public function test_a_verified_contractor_sends_a_proposal_with_the_service_fee(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $line = $this->line($site, 'electricien');

        $this->asContractor()->postJson("/api/v1/contractor/opportunities/quotes/{$line->id}/proposal", ['fee_amount' => 0, 'visit_date' => now()->toDateString(), 'visit_slot' => '07:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.fee_amount.0', 'Indiquez un montant positif.')
            ->assertJsonPath('errors.visit_date.0', 'La date de visite doit être future.')
            ->assertJsonPath('errors.visit_slot.0', 'Choisissez un créneau.');

        $proposal = $this->propose($line->id)
            ->assertCreated()
            ->assertJsonPath('message', 'Proposition envoyée au promoteur.')
            ->assertJsonPath('data.money', ['fee_amount' => 25000, 'service_fee_percent' => 10, 'service_fee_amount' => 2500, 'total_amount' => 27500, 'net_amount' => 25000, 'paid' => false])
            ->assertJsonPath('data.display_status', ['value' => 'proposee', 'label' => 'En attente'])
            ->assertJsonPath('data.tracking.1.state', 'current')
            ->assertJsonPath('data.mission', null)
            ->assertJsonPath('data.actions.withdraw', true)
            ->assertJsonPath('data.options.vat_rate', 18)
            ->json('data');

        $this->propose($line->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.proposal.0', 'Vous avez déjà répondu : consultez votre proposition.');
        $this->getJson("/api/v1/contractor/opportunities/quotes/{$line->id}")
            ->assertJsonPath('data.my_response.amount', 27500)
            ->assertJsonPath('data.response.action', 'Voir ma proposition');
        $this->getJson('/api/v1/contractor/quote-proposals')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $proposal['id'])
            ->assertJsonPath('meta.tabs.0', ['key' => 'en_attente', 'label' => 'En attente', 'count' => 1]);

        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")
            ->assertJsonPath('data.trades.0.offers.0.id', $proposal['id'])
            ->assertJsonPath('data.trades.0.offers.0.amount', 27500)
            ->assertJsonPath('data.trades.0.offers.0.technician.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.trades.0.offers.0.technician.experience_years', 8)
            ->assertJsonPath('data.trades.0.offers.0.visit_slot', '10:00 – 12:00')
            ->assertJsonPath('data.ready_to_order', true);

        $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$proposal['id']}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'retiree')
            ->assertJsonPath('data.tracking.1.state', 'failed');
        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")->assertJsonPath('data.trades.0.offers', []);
    }

    public function test_an_unverified_contractor_cannot_propose(): void
    {
        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Soumis]);
        $line = $this->line($this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']), 'electricien');

        $this->asContractor()->propose($line->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.proposal.0', 'Vérification du profil requise pour répondre.');
    }

    public function test_the_ordered_proposal_becomes_a_visit_then_a_versioned_quote(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $line = $this->line($site, 'electricien');
        $proposal = $this->asContractor()->propose($line->id)->json('data.id');
        $other = $this->otherProposal($line->id, 40000);

        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['quote_bid_ids' => [$proposal], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'devis-entrepreneur'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 27500);

        $this->assertSame(QuoteBidStatus::NonRetenue, $other->refresh()->status);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'proposal.ordered')->exists());
        $this->assertTrue(Notification::query()->where('recipient_id', $other->contractor->user_id)->where('event_code', 'proposal.not_retained')->exists());

        $this->asContractor()->getJson("/api/v1/contractor/quote-proposals/{$proposal}")
            ->assertJsonPath('data.display_status.value', 'visite_planifiee')
            ->assertJsonPath('data.money.paid', true)
            ->assertJsonPath('data.mission.status.value', 'planifiee')
            ->assertJsonPath('data.mission.address', '12 Rue des Jardins')
            ->assertJsonPath('data.mission.promoter', ['name' => 'Awa Kouamé', 'phone' => '+225 07 12 34 99 99'])
            ->assertJsonPath('data.actions.mark_visited', true)
            ->assertJsonPath('data.actions.create_quote', false);

        $newDate = now()->addDays(6)->toDateString();
        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/visit/reschedule", ['reason' => 'Matériel en panne', 'date' => $newDate, 'slot' => '14:00 – 16:00'])
            ->assertOk()
            ->assertJsonPath('data.mission.reschedule.date', $newDate)
            ->assertJsonPath('data.actions.mark_visited', false);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'quote.reschedule_requested')->exists());

        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$site['id']}/quote-request/visits/{$proposal}/reschedule", ['decision' => 'accepter'])
            ->assertOk()
            ->assertJsonPath('message', 'Report de visite accepté')
            ->assertJsonPath('data.trades.0.visit.date', $newDate)
            ->assertJsonPath('data.trades.0.visit.slot', '14:00 – 16:00')
            ->assertJsonPath('data.trades.0.visit.reschedule', null);

        $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes")
            ->assertUnprocessable()
            ->assertJsonPath('errors.quote.0', 'Marquez d’abord la visite réalisée.');
        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/visit/done")
            ->assertOk()
            ->assertJsonPath('data.display_status.value', 'devis_a_rediger')
            ->assertJsonPath('data.actions.create_quote', true);

        $version = $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes")
            ->assertCreated()
            ->assertJsonPath('data.versions.0.version', 1)
            ->assertJsonPath('data.versions.0.status.value', 'brouillon')
            ->json('data.version_id');

        $this->patchJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$version}", [
            'items' => [
                ['designation' => 'Tableau électrique', 'unit' => 'u', 'quantity' => 1, 'unit_price' => 350000],
                ['designation' => 'Câblage', 'unit' => 'ml', 'quantity' => 120.5, 'unit_price' => 1500],
            ],
            'vat_applied' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.versions.0.total_ht', 530750)
            ->assertJsonPath('data.versions.0.vat_amount', 95535)
            ->assertJsonPath('data.versions.0.total_ttc', 626285)
            ->assertJsonPath('data.actions.edit_draft_id', $version);

        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$version}/send", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.signed.0', 'Signez le devis avant de l’envoyer.');
        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$version}/send", ['signed' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.works_duration.0', 'Indiquez la durée des travaux.');

        $this->patchJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$version}", ['works_duration' => '10 jours', 'validity_days' => 60])->assertOk();
        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$version}/send", ['signed' => true])
            ->assertOk()
            ->assertJsonPath('data.display_status.value', 'devis_envoye')
            ->assertJsonPath('data.versions.0.status.value', 'envoye')
            ->assertJsonPath('data.versions.0.document.type.code', 'site_quote')
            ->assertJsonPath('data.tracking.4.label', 'Devis remis (v1)');

        $this->assertSame(QuoteRequestStatus::Recu, QuoteRequest::query()->sole()->status);
        $this->assertSame(SiteStatus::Brouillon, Site::query()->whereKey($site['id'])->sole()->status);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'quote.sent')->exists());
        $this->patchJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$version}", ['works_duration' => '12 jours'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quote.0', 'Cette version est envoyée : créez une version corrigée.');

        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")
            ->assertJsonPath('data.status.value', 'recu')
            ->assertJsonPath('data.quotes_total_amount', 626285)
            ->assertJsonPath('data.trades.0.quote.version', 1)
            ->assertJsonPath('data.trades.0.quote.works_duration', '10 jours')
            ->assertJsonPath('data.trades.0.versions.0.id', $version);
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/quote-request/quotes/{$version}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.trades.0.versions.0.status.value', 'recu');
        $this->postJson("/api/v1/promoter/sites/{$site['id']}/quote-request/quotes/{$version}/acknowledge")->assertUnprocessable();
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'quote.received')->exists());

        $second = $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes")
            ->assertCreated()
            ->assertJsonPath('data.versions.1.version', 2)
            ->assertJsonPath('data.versions.1.total_ttc', 626285)
            ->json('data.version_id');
        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes")->assertUnprocessable();
        $this->patchJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$second}", ['vat_applied' => false])->assertJsonPath('data.versions.1.total_ttc', 530750);
        $this->postJson("/api/v1/contractor/quote-proposals/{$proposal}/quotes/{$second}/send", ['signed' => true])->assertOk();

        $this->assertSame(['DE', 'DE'], QuoteVersion::query()->pluck('number')->map(fn ($number) => substr($number, 0, 2))->all());
        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$site['id']}/quote-request")
            ->assertJsonPath('data.trades.0.quote.version', 2)
            ->assertJsonPath('data.trades.0.quote.total_amount', 530750)
            ->assertJsonCount(2, 'data.trades.0.versions');
    }

    public function test_a_refused_reschedule_keeps_the_agreed_date(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $proposal = $this->asContractor()->propose($this->line($site, 'electricien')->id)->json('data.id');
        $agreed = QuoteBid::query()->findOrFail($proposal)->visit_date->toDateString();
        $this->artisan('ochantier:simulate-quote-promoter', ['proposal' => $proposal, 'action' => 'commander'])->assertSuccessful();

        $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$proposal}/visit/reschedule", ['reason' => 'Empêchement', 'date' => now()->addDays(9)->toDateString(), 'slot' => '08:00 – 10:00'])->assertOk();
        $this->artisan('ochantier:simulate-quote-promoter', ['proposal' => $proposal, 'action' => 'refuser-report'])->assertSuccessful();

        $this->getJson("/api/v1/contractor/quote-proposals/{$proposal}")
            ->assertJsonPath('data.mission.date', $agreed)
            ->assertJsonPath('data.mission.status.value', 'planifiee');
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'visit.reschedule_refused')->exists());

        $this->artisan('ochantier:simulate-quote-promoter', ['proposal' => $proposal, 'action' => 'accuser-reception'])
            ->expectsOutputToContain('Aucun devis envoyé')
            ->assertFailed();
    }

    public function test_cancelling_the_request_closes_the_pending_proposals(): void
    {
        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $proposal = $this->asContractor()->propose($this->line($site, 'electricien')->id)->json('data.id');

        $this->asPromoter()->deleteJson("/api/v1/promoter/sites/{$site['id']}/quote-request")->assertOk();

        $this->asContractor()->getJson("/api/v1/contractor/quote-proposals/{$proposal}")
            ->assertJsonPath('data.status.value', 'non_retenue')
            ->assertJsonPath('data.notice', 'Le promoteur a annulé sa demande de devis. Cette fiche reste consultable.');
        $this->getJson('/api/v1/contractor/quote-proposals?tab=refusees')->assertJsonCount(1, 'data');
    }

    public function test_eligible_contractors_are_told_about_new_opportunities(): void
    {
        $plumber = $this->contractorAccount('Plomberie Akwaba', 'plombier', 'Abidjan');
        $farAway = $this->contractorAccount('Électricité Bouaké', 'electricien', 'Bouaké');

        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $notification = Notification::query()->where('event_code', 'opportunity.quote')->sole();

        $this->assertSame($this->user->id, $notification->recipient_id);
        $this->assertSame('Nouvelle demande de devis · Électricien', $notification->title);
        $this->assertEquals(['type' => 'quote_opportunity', 'id' => $this->line($site, 'electricien')->id], $notification->data['target']);

        $this->publishedContract('Résidence Les Acacias', 'Cocody');

        $recipients = Notification::query()->where('event_code', 'opportunity.offer')->pluck('recipient_id')->all();
        $this->assertEqualsCanonicalizing([$this->user->id, $plumber->user_id], $recipients);
        $this->assertNotContains($farAway->user_id, $recipients);
    }

    private function propose(string $lineId): TestResponse
    {
        return $this->postJson("/api/v1/contractor/opportunities/quotes/{$lineId}/proposal", [
            'fee_amount' => 25000,
            'visit_date' => now()->addDays(3)->toDateString(),
            'visit_slot' => '10:00 – 12:00',
            'visit_duration' => '2 h',
            'comment' => 'Je passerai avec un apprenti.',
        ]);
    }

    /**
     * Proposition d’un autre entrepreneur vérifié, avec un compte.
     */
    private function otherProposal(string $lineId, int $fee): QuoteBid
    {
        $contractor = $this->contractorAccount('Ivoire Élec', 'electricien', 'Abidjan');

        return app(QuoteRequests::class)->addBid($this->line(['id' => QuoteRequest::query()->sole()->site_id], 'electricien'), $contractor, $fee, CarbonImmutable::today()->addDays(2), '08:00 – 10:00');
    }

    private function contractorAccount(string $name, string $trade, string $city): Contractor
    {
        $contractor = User::factory()->contractor()->create()->contractor()->create([
            'reference' => 'ENT-'.random_int(20000, 99999),
            'kind' => ContractorKind::Entreprise,
            'name' => $name,
            'city_id' => $this->city($city),
            'status' => ContractorStatus::Actif,
            'verification_status' => ContractorVerificationStatus::Valide,
        ]);
        $contractor->trades()->attach($this->trade($trade));

        return $contractor;
    }
}
