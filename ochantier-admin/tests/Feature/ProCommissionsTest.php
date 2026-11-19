<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Commissions O’CHANTIER PRO dans le Back Office : « Règles & commissions », onglets « Commissions », onglet
 * « Commission & versements » d’un professionnel et reversements aux professionnels.
 */
class ProCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const CONTRACTOR = '01a1f369-0000-7000-8000-0000000000c1';

    private const PAYOUT = '01a1f369-0000-7000-8000-0000000000a9';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['contractors.read', 'offers.read', 'finance.read', 'payouts.read', 'payouts.create', 'payouts.approve', 'settings.read', 'settings.update']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ledger(): array
    {
        return ['data' => [[
            'id' => 'devis:b1', 'source' => ['value' => 'demande_devis', 'label' => 'Demande de devis'], 'reference' => 'DV-2026-0012', 'label' => 'Visite · Électricien',
            'opportunity' => ['type' => 'demande_devis', 'id' => 'l1'], 'site' => ['id' => 's1', 'label' => 'Villa Riviera'], 'contractor' => ['id' => self::CONTRACTOR, 'name' => 'BATI-CI Construction'],
            'payer' => 'Awa Kouamé', 'base' => 27500, 'rate' => 10, 'commission' => 2500, 'professional_net' => 25000, 'status' => ['value' => 'collectee', 'label' => 'Collectée'],
            'date' => '2026-11-19T10:00:00+00:00', 'payment_reference' => 'PAY-2026-000051', 'service_done' => true,
            'payout' => ['id' => self::PAYOUT, 'reference' => 'VP-2026-000001', 'status' => ['value' => 'a_payer', 'label' => 'À valider']],
        ]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'totals' => [
            'commission' => ['a_collecter' => 0, 'collectee' => 2500, 'remboursee' => 0], 'by_source' => [], 'collected_amount' => 27500, 'professional_net' => 25000, 'count' => 1,
        ]]];
    }

    public function test_the_rules_are_read_from_the_settings_and_only_changed_values_are_saved(): void
    {
        $field = fn (string $key, string $label, int $value, string $unit) => ['key' => $key, 'group' => 'Propositions', 'label' => $label, 'type' => 'int', 'min' => 0, 'max' => 50, 'unit' => $unit, 'value' => $value];
        Http::fake([
            self::API.'/settings/values' => Http::response(['message' => 'Paramètre enregistré.', 'data' => []]),
            self::API.'/settings' => Http::response(['data' => ['groups' => [['title' => 'Propositions', 'fields' => [
                $field('quotes.service_fee_percent', 'Frais de service sur les propositions de devis', 10, '%'),
                $field('contractor_offers.commission_percent', 'Commission O’CHANTIER sur les offres contractuelles (figée à la publication)', 8, '%'),
                $field('pro.compared_offers', 'Offres de professionnels montrées au promoteur', 3, 'offres'),
                $field('quotes.response_hours', 'Délai de réponse à une demande de devis', 72, 'h'),
                $field('contractor_offers.response_hours', 'Délai de réponse à une offre contractuelle', 72, 'h'),
            ]]]]]),
        ]);

        $this->post('/entrepreneurs/regles', ['quotes_service_fee_percent' => '10', 'contractor_offers_commission_percent' => '12', 'pro_compared_offers' => '3', 'reason' => 'abc'])
            ->assertSessionHasErrors(['reason' => 'Le motif est trop court.']);

        $this->from('/entrepreneurs')->post('/entrepreneurs/regles', ['quotes_service_fee_percent' => '10', 'contractor_offers_commission_percent' => '12', 'pro_compared_offers' => '2', 'reason' => 'Nouvelle grille PRO.'])
            ->assertRedirect('/entrepreneurs')
            ->assertSessionHas('inertia.flash_data.toast', '2 réglages enregistrés.');

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/settings/values') && $r['key'] === 'contractor_offers.commission_percent' && $r['value'] === 12 && $r['reason'] === 'Nouvelle grille PRO.');
        Http::assertNotSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/settings/values') && $r['key'] === 'quotes.service_fee_percent');
    }

    public function test_the_commissions_tab_reads_the_register_for_its_source(): void
    {
        Http::fake([
            self::API.'/pro-commissions*' => Http::response($this->ledger()),
            self::API.'/quote-requests*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 1, 'total' => 0, 'counts' => ['ouverte' => 0], 'trades' => [], 'cities' => []]]),
        ]);

        $this->get('/demandes-devis?onglet=commissions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Quotes/Index')
                ->where('ledger.totals', ['collected' => 2500, 'toCollect' => 0, 'collectedAmount' => 27500, 'professionalNet' => 25000])
                ->where('ledger.lines.0.href', '/demandes-devis/l1')
                ->where('ledger.lines.0.rate', '10 %')
                ->where('ledger.lines.0.payout.reference', 'VP-2026-000001')
                ->where('tabs.4', ['key' => 'commissions', 'label' => 'Commissions', 'icon' => 'percent', 'count' => 1]));

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/pro-commissions') && str_contains($r->url(), 'source=demande_devis'));

    }

    public function test_the_commissions_tab_needs_the_finance_right(): void
    {
        $this->signIn(['offers.read']);
        Http::fake([self::API.'/quote-requests*' => Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 1, 'total' => 0, 'counts' => ['ouverte' => 0], 'trades' => [], 'cities' => []]])]);

        $this->get('/demandes-devis?onglet=commissions')->assertInertia(fn (Assert $page) => $page->where('ledger', null)->missing('tabs.4'));
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/pro-commissions'));
    }

    public function test_the_payout_screens_prepare_and_approve_through_the_api(): void
    {
        $payout = ['id' => self::PAYOUT, 'reference' => 'VP-2026-000001', 'contractor' => ['id' => self::CONTRACTOR, 'reference' => 'ENT-00012', 'name' => 'BATI-CI Construction'], 'total_amount' => 25000, 'items_count' => 1,
            'status' => ['value' => 'a_payer', 'label' => 'À valider'], 'prepared_by' => 'Awa Koné', 'approved_by' => null, 'created_at' => '2026-11-19T10:00:00+00:00', 'paid_at' => null, 'cancellation_reason' => null,
            'actions' => ['approve' => true, 'retry' => false, 'cancel' => true]];
        Http::fake([
            self::API.'/contractor-payouts/'.self::PAYOUT.'/approve' => Http::response(['message' => 'Versement envoyé.', 'data' => $payout]),
            self::API.'/contractor-payouts/'.self::PAYOUT => Http::response(['data' => [...$payout, 'method' => ['operator' => 'Orange Money', 'number' => '+225 07 •• •• •• 78'], 'items' => [['source_key' => 'devis:b1', 'reference' => 'DV-2026-0012', 'label' => 'Visite · Électricien', 'site_label' => 'Villa Riviera', 'amount' => 25000]], 'transactions' => []]]),
            self::API.'/contractor-payouts?*' => Http::response(['data' => ['payable' => [['contractor' => $payout['contractor'], 'items_count' => 1, 'total' => 25000, 'number' => '+225 07 •• •• •• 78', 'operator' => 'Orange Money', 'blockers' => [], 'items' => []]], 'payouts' => [$payout]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1]]),
            self::API.'/contractor-payouts' => Http::response(['message' => 'Versement préparé : une autre personne doit le valider.', 'data' => $payout], 201),
            self::API.'/contractors/'.self::CONTRACTOR.'/payment-method' => Http::response(['message' => 'Numéro enregistré : une autre personne doit le confirmer.', 'data' => []], 201),
        ]);

        $this->get('/paiements/professionnels')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Payments/ContractorPayouts')
                ->where('kpis', ['ready' => 25000, 'blocked' => 0, 'contractors' => 1])
                ->where('payouts.data.0.status', ['value' => 'a_payer', 'label' => 'À valider', 'tone' => 'a']));

        $this->post('/paiements/professionnels/versements', ['contractor_id' => self::CONTRACTOR])->assertRedirect('/paiements/professionnels/versements/'.self::PAYOUT);
        $this->get('/paiements/professionnels/versements/'.self::PAYOUT)->assertInertia(fn (Assert $page) => $page->component('Payments/ContractorPayout')->where('payout.items.0.amount', 25000)->where('payout.canApprove', true));
        $this->from('/paiements/professionnels/versements/'.self::PAYOUT)->post('/paiements/professionnels/versements/'.self::PAYOUT.'/validation')->assertSessionHas('inertia.flash_data.toast', 'Versement envoyé.');
        $this->from('/entrepreneurs/'.self::CONTRACTOR.'/versements')->post('/paiements/professionnels/'.self::CONTRACTOR.'/numero', ['operator_id' => 'op1', 'dial_code' => '225', 'phone_number' => '0712345678'])->assertSessionHas('inertia.flash_data.toast');

        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/contractors/'.self::CONTRACTOR.'/payment-method') && $r['phone_number'] === '0712345678');
    }
}
