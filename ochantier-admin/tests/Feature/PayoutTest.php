<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages de reversement branchées sur l’API (api/v1/admin/payouts).
 */
class PayoutTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000d1';

    private const BATCH = '01a16ab6-0000-7000-8000-0000000000d2';

    private const WORKER = '01a16ab6-0000-7000-8000-0000000000d3';

    private const META = ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['payouts.read', 'payouts.create', 'payouts.approve', 'payouts.export'], 'must_change_password' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payout(): array
    {
        return ['id' => self::ID, 'reference' => 'REV-2026-000001', 'batch' => ['id' => self::BATCH, 'reference' => 'LOT-2026-000001'], 'worker' => ['id' => self::WORKER, 'reference' => 'OUV-1', 'full_name' => 'Kouamé Koffi'], 'site' => ['id' => 's1', 'label' => 'Palmiers'], 'mission' => ['id' => 'm1', 'reference' => 'MI-1'], 'period_start' => '2026-11-02', 'period_end' => '2026-11-02', 'days' => 1, 'gross_amount' => 25000, 'commission_amount' => 2300, 'deduction_amount' => 0, 'bonus_amount' => 0, 'net_amount' => 22700, 'number' => '07 •• •• 78', 'operator' => 'Orange Money', 'status' => ['value' => 'rejete', 'label' => 'Rejeté']];
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(): array
    {
        return ['id' => self::BATCH, 'reference' => 'LOT-2026-000001', 'status' => ['value' => 'a_valider', 'label' => 'À valider'], 'payout_count' => 1, 'total_net_amount' => 22700, 'total_commission_amount' => 2300, 'results' => null, 'prepared_by' => 'Awa Diallo', 'approved_by' => null, 'created_at' => '2026-11-06T10:00:00+00:00', 'executed_at' => null];
    }

    public function test_the_payable_list_translates_filters(): void
    {
        Http::fake([self::API.'/payouts/payable*' => Http::response(['data' => [['key' => 'a:b', 'net_amount' => 22700, 'blockers' => []]], 'meta' => [...self::META, 'counts' => ['payable' => 1, 'blocked' => 0, 'payable_amount' => 22700, 'blocked_amount' => 0], 'pending_methods' => 2]])]);

        $this->get('/paiements/a-reverser?q=Kouamé&etat=blocked')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Payable')
            ->where('rows.data.0.key', 'a:b')
            ->where('kpis.payable_amount', 22700)
            ->where('kpis.pendingMethods', 2)
            ->where('can.create', true));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'state=blocked') && str_contains($request->url(), 'search=Kouam'));
    }

    public function test_preparing_a_batch_opens_it_and_a_refusal_comes_back_as_a_message(): void
    {
        $items = [['schedule_id' => 'a', 'assignment_id' => 'b']];

        Http::fake([self::API.'/payouts/batches' => Http::sequence()
            ->push(['message' => 'Lot préparé.', 'data' => ['id' => self::BATCH]], 201)
            ->push(['message' => 'Refus', 'errors' => ['payout' => ['Une ligne choisie n’est plus à reverser.']]], 422)]);

        $this->post('/paiements/a-reverser/lot', ['items' => $items])->assertRedirect('/paiements/lots/'.self::BATCH);
        $this->from('/paiements/a-reverser')->post('/paiements/a-reverser/lot', ['items' => $items])->assertRedirect('/paiements/a-reverser');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/payouts/batches' && $request['items'] === $items);
    }

    public function test_the_payout_page_is_built_from_the_api(): void
    {
        Http::fake([self::API.'/payouts/'.self::ID => Http::response(['data' => [...$this->payout(), 'schedule' => null, 'prepared_by' => 'Awa', 'approved_by' => null, 'approved_at' => null, 'work_days' => [['id' => 'd1', 'date' => '2026-11-02', 'worked_minutes' => 480]], 'adjustments' => [['id' => 'x', 'kind' => ['value' => 'retenue', 'label' => 'Retenue'], 'amount' => 1000, 'reason' => 'Motif', 'basis' => null, 'status' => ['value' => 'proposee', 'label' => 'Proposée'], 'proposed_by' => 'Awa', 'decided_by' => null, 'can_decide' => true]], 'transactions' => [['id' => 't', 'reference' => 'PAY-1', 'amount' => 22700, 'operator' => 'Orange Money', 'number' => '07', 'status' => ['value' => 'echouee', 'label' => 'Échouée'], 'failure' => 'Numéro invalide', 'initiated_at' => '2026-11-06T10:00:00+00:00']], 'can_adjust' => false, 'can_cancel' => true, 'can_retry' => true]])]);

        $this->get('/paiements/reversements/'.self::ID)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Payout')
            ->where('payout.status.tone', 'r')
            ->where('payout.net', 22700)
            ->where('payout.adjustments.0.status.tone', 'a')
            ->where('payout.transactions.0.status.tone', 'r')
            ->where('payout.canRetry', true));
    }

    public function test_the_lists_are_built_from_the_api(): void
    {
        Http::fake([
            self::API.'/payouts/batches?*' => Http::response(['data' => [$this->batch()], 'meta' => [...self::META, 'counts' => ['brouillon' => 0, 'a_valider' => 1, 'partiel' => 0, 'echoue' => 0]]]),
            self::API.'/payouts/batches/'.self::BATCH => Http::response(['data' => [...$this->batch(), 'pending_adjustments' => 0, 'payouts' => [$this->payout()], 'can_submit' => false, 'can_cancel' => true, 'can_approve' => true, 'can_resume' => false]]),
            self::API.'/payouts/payment-methods/pending*' => Http::response(['data' => [['id' => 'p', 'operator' => 'Orange Money', 'number' => '07', 'proposed_by' => 'Awa', 'proposed_at' => '2026-11-06T10:00:00+00:00', 'can_decide' => true, 'worker' => ['id' => self::WORKER, 'reference' => 'OUV-1', 'full_name' => 'Kouamé']]], 'meta' => self::META]),
            self::API.'/payouts?*' => Http::response(['data' => [$this->payout()], 'meta' => [...self::META, 'counts' => ['statuses' => ['rejete' => 1, 'confirme' => 0], 'paid_amount' => 0]]]),
            self::API.'/payouts/export*' => Http::response('a;b', 200, ['Content-Disposition' => 'attachment; filename="reversements.csv"']),
        ]);

        $this->get('/paiements/reversements?statut=rejete&lot='.self::BATCH)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Payouts')->where('kpis.rejected', 1)->where('payouts.data.0.status.tone', 'r'));
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'status=rejete') && str_contains($request->url(), 'batch_id='.self::BATCH));

        $this->get('/paiements/lots')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Batches')->where('batches.data.0.status.tone', 'a'));
        $this->get('/paiements/lots/'.self::BATCH)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Batch')->where('batch.canApprove', true)->where('batch.payouts.0.net', 22700));
        $this->get('/paiements/numeros')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Methods')->where('methods.data.0.can_decide', true));
        $this->get('/paiements/reversements/export')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="reversements.csv"');
    }

    public function test_actions_go_to_the_api_and_refusals_become_messages(): void
    {
        Http::fake([self::API.'/*' => Http::response(['message' => 'Fait.', 'data' => []])]);

        $calls = [
            ['/paiements/lots/'.self::BATCH.'/soumission', [], 'payouts/batches/'.self::BATCH.'/submit'],
            ['/paiements/lots/'.self::BATCH.'/validation', [], 'payouts/batches/'.self::BATCH.'/approve'],
            ['/paiements/lots/'.self::BATCH.'/reprise', [], 'payouts/batches/'.self::BATCH.'/resume'],
            ['/paiements/lots/'.self::BATCH.'/annulation', [], 'payouts/batches/'.self::BATCH.'/cancel'],
            ['/paiements/lots/'.self::BATCH.'/renvoi', ['reason' => 'À revoir.'], 'payouts/batches/'.self::BATCH.'/reject'],
            ['/paiements/reversements/'.self::ID.'/renvoi', [], 'payouts/'.self::ID.'/retry'],
            ['/paiements/reversements/'.self::ID.'/retrait', ['reason' => 'À retirer.'], 'payouts/'.self::ID.'/cancel'],
            ['/paiements/reversements/'.self::ID.'/ajustements', ['kind' => 'bonus', 'amount' => '500', 'reason' => 'Ponctualité'], 'payouts/'.self::ID.'/adjustments'],
            ['/paiements/ouvriers/'.self::WORKER.'/numero', ['operator_id' => 'o', 'dial_code' => '225', 'phone_number' => '0712345678'], 'workers/'.self::WORKER.'/payment-method'],
            ['/paiements/ouvriers/'.self::WORKER.'/numero/'.self::ID.'/confirmation', [], 'workers/'.self::WORKER.'/payment-method/'.self::ID.'/approve'],
            ['/paiements/ouvriers/'.self::WORKER.'/numero/'.self::ID.'/refus', ['reason' => 'Non vérifié.'], 'workers/'.self::WORKER.'/payment-method/'.self::ID.'/reject'],
        ];

        foreach ($calls as [$url, $data, $api]) {
            $this->from('/paiements/lots')->post($url, $data)->assertRedirect('/paiements/lots');
            Http::assertSent(fn (ClientRequest $request) => $request->url() === self::API.'/'.$api);
        }
    }

    public function test_a_field_error_stays_under_its_field(): void
    {
        Http::fake([self::API.'/*' => Http::response(['message' => 'Erreur', 'errors' => ['phone_number' => ['Ce numéro n’est pas un numéro Orange Money.']]], 422)]);

        $this->from('/ouvriers/'.self::WORKER.'/paiements')->post('/paiements/ouvriers/'.self::WORKER.'/numero', ['operator_id' => 'o', 'dial_code' => '225', 'phone_number' => '05'])
            ->assertSessionHasErrors('phone_number');
    }
}
