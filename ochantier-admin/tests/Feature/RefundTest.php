<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Régularisations et remboursements branchés sur l’API (api/v1/admin/regularisations et refunds).
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const MISSION = '01a16ab6-0000-7000-8000-0000000000e1';

    private const REFUND = '01a16ab6-0000-7000-8000-0000000000e2';

    private const META = ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Finance', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['payments.read', 'payments.update', 'payments.approve', 'payments.export']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mission(): array
    {
        return ['id' => self::MISSION, 'reference' => 'MI-2026-0001', 'site' => ['id' => 's', 'label' => 'Résidence'], 'promoter' => ['id' => 'p', 'full_name' => 'Yao Konan'], 'closed_at' => '2026-11-01T10:00:00+00:00', 'state' => ['value' => 'a_faire', 'label' => 'À régulariser'], 'days' => 2, 'workers_amount' => 50000, 'commission_amount' => 5000, 'y_rate' => 10.0, 'total' => 55000, 'planned_total' => 300000, 'paid' => 90000, 'refunded' => 0, 'balance' => -35000, 'blockers' => [], 'schedule' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function refund(): array
    {
        return ['id' => self::REFUND, 'reference' => 'REM-2026-000001', 'origin' => ['value' => 'regularisation', 'label' => 'Fin de mission'], 'status' => ['value' => 'demande', 'label' => 'Demandé'], 'amount' => 35000, 'reason' => 'Trop-perçu.', 'refusal_reason' => null, 'mission' => ['id' => self::MISSION, 'reference' => 'MI-2026-0001'], 'site' => ['id' => 's', 'label' => 'Résidence'], 'promoter' => ['id' => 'p', 'full_name' => 'Yao Konan'], 'transaction' => null, 'number' => null, 'operator' => null, 'requested_by' => 'Awa', 'approved_by' => null, 'second_approver' => null, 'approvals_required' => 1, 'created_at' => '2026-11-01T10:00:00+00:00', 'executed_at' => null, 'can_approve' => false, 'can_retry' => false, 'can_refuse' => true, 'can_change_number' => true];
    }

    public function test_the_regularisation_list_translates_filters(): void
    {
        Http::fake([self::API.'/regularisations*' => Http::response(['data' => [$this->mission()], 'meta' => [...self::META, 'counts' => ['a_faire' => 1, 'faite' => 0]]])]);

        $this->get('/paiements/regularisations?q=Résidence&etat=a_faire')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Regularisations')
            ->where('missions.data.0.balance', -35000)
            ->where('kpis.toDo', 1));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'search=') && str_contains($request->url(), 'state=a_faire'));
    }

    public function test_the_fiche_and_the_run_reach_the_api(): void
    {
        Http::fake([self::API.'/regularisations/'.self::MISSION => Http::sequence()
            ->push(['data' => [...$this->mission(), 'refundable' => 0, 'refunds' => [$this->refund()]]])
            ->push(['data' => [], 'message' => 'Mission régularisée.'])]);

        $this->get('/paiements/regularisations/'.self::MISSION)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Regularisation')
            ->where('mission.refunds.0.status.tone', 'a'));

        $this->post('/paiements/regularisations/'.self::MISSION.'/calcul')->assertRedirect();
        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/regularisations/'.self::MISSION));
    }

    public function test_a_business_refusal_becomes_a_message(): void
    {
        Http::fake([self::API.'/refunds/'.self::REFUND.'/approve' => Http::response(['message' => 'Refus', 'errors' => ['refund' => ['Une autre personne doit valider ce remboursement.']]], 422)]);

        $this->post('/paiements/remboursements/'.self::REFUND.'/validation')->assertRedirect()
            ->assertInertiaFlash('toastError', 'Une autre personne doit valider ce remboursement.');
    }

    public function test_the_refund_pages_and_number_entry_reach_the_api(): void
    {
        Http::fake([
            self::API.'/refunds/'.self::REFUND.'/number' => Http::response(['data' => [], 'message' => 'Numéro enregistré.']),
            self::API.'/refunds/'.self::REFUND => Http::response(['data' => [...$this->refund(), 'saved_numbers' => [], 'operators' => [['id' => 'o', 'name' => 'Orange']], 'transactions' => []]]),
            self::API.'/refunds*' => Http::response(['data' => [$this->refund()], 'meta' => [...self::META, 'counts' => ['statuses' => ['echoue' => 2], 'executed_amount' => 1000, 'pending_amount' => 35000]]]),
        ]);

        $this->get('/paiements/remboursements?statut=demande')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Refunds')->where('kpis.failed', 2));
        $this->get('/paiements/remboursements/'.self::REFUND)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Payments/Refund')->where('refund.operators.0.name', 'Orange'));

        $this->post('/paiements/remboursements/'.self::REFUND.'/numero', ['operator_id' => 'o', 'dial_code' => '225', 'phone_number' => '0712345678'])->assertRedirect();
        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST' && $request['phone_number'] === '0712345678');
    }
}
