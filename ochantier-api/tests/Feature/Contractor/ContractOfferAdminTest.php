<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorBidStatus;
use App\Enums\ContractorVerificationStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ContractorBid;
use App\Models\Notification;
use App\Models\Offer;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Support\Facades\DB;

/**
 * Offres contractuelles dans le Back Office : liste et statut affiché, fiche (prix promoteur, commission Y,
 * acompte X), prolongation, annulation avant l’acompte, relances ; elles ne figurent plus dans Besoins.
 */
class ContractOfferAdminTest extends ContractorMarketTestCase
{
    private string $adminToken;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->adminToken = $this->adminTokenFor(Admin::factory()->role('operations')->create());
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
    }

    public function test_the_list_and_the_fiche_show_the_responses_at_the_promoter_price(): void
    {
        $this->asAdmin()->getJson('/api/v1/admin/contract-offers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.ouverte', 1)
            ->assertJsonPath('data.0.status', ['value' => 'ouverte', 'label' => 'Ouverte'])
            ->assertJsonPath('data.0.price', 3300000)
            ->assertJsonPath('data.0.responses_count', 0);

        $bid = $this->respond();

        $this->asAdmin()->getJson('/api/v1/admin/contract-offers?status=en_comparaison&search=acacias')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.responses_count', 1)
            ->assertJsonPath('data.0.counter_proposals_count', 1)
            ->assertJsonPath('data.0.promoter.name', 'Awa Kouamé');

        $fiche = $this->asAdmin()->getJson('/api/v1/admin/contract-offers/'.$this->offer->id)
            ->assertOk()
            ->assertJsonPath('data.responses.0.id', $bid)
            ->assertJsonPath('data.responses.0.mode.value', 'contre_proposition')
            ->assertJsonPath('data.responses.0.amount', 3000000)
            ->assertJsonPath('data.responses.0.is_shown', true)
            ->assertJsonPath('data.responses.0.is_quote_author', false)
            ->assertJsonPath('data.quote_request', null)
            ->assertJsonPath('data.attribution', null)
            ->assertJsonPath('data.actions', ['extend' => true, 'cancel' => true, 'remind_promoter' => true, 'remind_contractors' => true])
            ->assertJsonPath('data.timeline.0.what', 'a fait une contre-proposition')
            ->json('data');

        $this->assertSame($fiche['responses'][0]['amount'] + $fiche['responses'][0]['commission'], $fiche['responses'][0]['total']);

        $this->asAdmin()->getJson('/api/v1/admin/offers')->assertOk()->assertJsonPath('meta.total', 0);
        $this->asAdmin()->getJson('/api/v1/admin/offers?offer_type=contractuelle')->assertUnprocessable();
    }

    public function test_the_deposit_attributes_the_site_and_closes_the_decisions(): void
    {
        $bid = $this->respond();
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->offer->site_id}/contractor-offers/{$bid}/choose")->assertOk();

        $this->asAdmin()->getJson('/api/v1/admin/contract-offers/'.$this->offer->id)
            ->assertJsonPath('data.status.value', 'acompte_attendu')
            ->assertJsonPath('data.actions.remind_promoter', true)
            ->assertJsonPath('data.actions.remind_contractors', false);

        DB::table('payment_holds')->insert(['target_type' => 'contractor_bid', 'target_id' => $bid, 'holder_key' => 'test', 'expires_at' => now()->addMinutes(10)]);
        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/cancellation', ['reason' => 'Demande du promoteur.'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.offer.0', 'Le promoteur est en train de payer l’acompte : réessayez dans quelques minutes.');
        DB::table('payment_holds')->delete();

        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-admin'])->assertCreated();

        $this->asAdmin()->getJson('/api/v1/admin/contract-offers/'.$this->offer->id)
            ->assertJsonPath('data.status', ['value' => 'attribuee', 'label' => 'Chantier attribué'])
            ->assertJsonPath('data.retained.contractor.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.attribution.deposit.status.value', 'payee')
            ->assertJsonPath('data.actions', ['extend' => false, 'cancel' => false, 'remind_promoter' => false, 'remind_contractors' => false])
            ->assertJsonPath('data.timeline.0.what', 'a payé l’acompte');

        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/cancellation', ['reason' => 'Demande du promoteur.'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.offer.0', 'L’acompte est payé : le chantier est attribué, l’offre ne peut plus être annulée.');
    }

    public function test_an_open_offer_can_be_extended_reminded_then_cancelled(): void
    {
        $bid = $this->respond();
        $this->travel(4)->days();
        $this->adminToken = $this->adminTokenFor(Admin::factory()->role('operations')->create());

        $this->asAdmin()->getJson('/api/v1/admin/contract-offers/'.$this->offer->id)->assertJsonPath('data.status.value', 'expiree');
        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/extension', ['deadline' => now()->addDays(5)->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'en_comparaison')
            ->assertJsonPath('data.extension_count', 1);

        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/reminders', ['target' => 'promoter'])
            ->assertOk()
            ->assertJsonPath('message', 'Relance envoyée au promoteur.');
        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/reminders', ['target' => 'promoter'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target.0', 'Une relance a déjà été envoyée aujourd’hui au promoteur.');
        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/reminders', ['target' => 'contractors'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target.0', 'Aucun professionnel à relancer aujourd’hui (déjà relancés ou tous ont répondu).');

        $this->asAdmin()->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/cancellation', ['reason' => 'Chantier reporté à l’an prochain.'])
            ->assertOk()
            ->assertJsonPath('message', 'Offre contractuelle annulée.')
            ->assertJsonPath('data.status.value', 'annulee')
            ->assertJsonPath('data.responses.0.status.value', 'annulee')
            ->assertJsonPath('data.timeline.0.what', 'a annulé l’offre');

        $this->assertSame(ContractorBidStatus::Annulee, ContractorBid::query()->findOrFail($bid)->status);
        $this->assertSame('brouillon', $this->offer->site->refresh()->status->value);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'offer.reminder')->exists());
        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'offer.cancelled')->exists());
        $this->assertStringContainsString('annulée par O’CHANTIER', Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'offer.not_retained')->sole()->body);
        $this->assertSame(1, AuditLog::query()->where('action', 'contract_offer.cancelled')->count());
    }

    public function test_the_commission_rate_is_frozen_on_each_offer(): void
    {
        $this->assertSame(8.0, $this->offer->refresh()->commission_percent);
        $this->asAdmin()->getJson('/api/v1/admin/contract-offers')->assertJsonPath('meta.rates.commission_percent', 8);

        $settings = $this->adminTokenFor(Admin::factory()->role('super_admin')->create());
        $this->withAccessToken($settings)->postJson('/api/v1/admin/settings/values', ['key' => 'contractor_offers.commission_percent', 'value' => 12, 'reason' => 'Nouvelle grille PRO.'])->assertOk();
        $this->withAccessToken($settings)->postJson('/api/v1/admin/settings/values', ['key' => 'pro.compared_offers', 'value' => 2, 'reason' => 'Comparaison resserrée.'])->assertOk();

        $bid = $this->respond();
        $this->asAdmin()->getJson('/api/v1/admin/contract-offers/'.$this->offer->id)
            ->assertJsonPath('data.rates.commission_percent', 8)
            ->assertJsonPath('data.responses.0.commission', 240000)
            ->assertJsonPath('data.responses.0.total', 3240000);
        $this->asAdmin()->getJson('/api/v1/admin/contract-offers')->assertJsonPath('meta.rates.commission_percent', 12);

        $newer = $this->publishedContract('Villa Les Palmiers', 'Cocody');
        $this->assertSame(12.0, $newer->commission_percent);
        $this->asContractor()->getJson("/api/v1/contractor/offer-responses/{$bid}")
            ->assertJsonPath('data.money.commission_percent', 8)
            ->assertJsonPath('data.notice', 'Le promoteur compare au maximum 2 offres et en retient une seule.');
    }

    public function test_only_authorised_admins_decide(): void
    {
        $support = $this->adminTokenFor(Admin::factory()->role('support')->create());

        $this->withAccessToken($support)->postJson('/api/v1/admin/contract-offers/'.$this->offer->id.'/cancellation', ['reason' => 'Demande du promoteur.'])->assertForbidden();
        $this->asAdmin()->getJson('/api/v1/admin/contract-offers/01a1f369-0000-7000-8000-000000000000')->assertNotFound();
    }

    private function respond(): string
    {
        return $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(39)->toDateString(), 'team_size' => 6,
        ])->assertCreated()->json('data.id');
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }

    /**
     * @return $this
     */
    private function asAdmin(): static
    {
        return $this->withAccessToken($this->adminToken);
    }
}
