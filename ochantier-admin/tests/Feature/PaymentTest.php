<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Paiements branchées sur l’API (api/v1/admin/payments).
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000c1';

    private const SCHEDULE = '01a16ab6-0000-7000-8000-0000000000c2';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['payments.read', 'payments.update', 'payments.export'], 'must_change_password' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(): array
    {
        return ['id' => self::ID, 'reference' => 'PAY-2026-000001', 'status' => ['value' => 'reussie', 'label' => 'Réussie'], 'purpose' => ['value' => 'acompte', 'label' => 'Acompte'], 'label' => 'Acompte · Résidence', 'site' => ['id' => 's1', 'reference' => 'CH-1', 'label' => 'Résidence'], 'promoter' => ['name' => 'Bâtir'], 'operator' => 'Orange Money', 'amount' => 100000, 'fees_amount' => 1500, 'total_amount' => 101500, 'initiated_at' => '2026-11-04T09:00:00+00:00', 'completed_at' => '2026-11-04T09:01:00+00:00'];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(): array
    {
        return ['id' => self::SCHEDULE, 'reference' => 'ECH-2026-000001', 'state' => ['value' => 'en_retard', 'label' => 'En retard'], 'type' => ['value' => 'hebdomadaire', 'label' => 'Hebdomadaire'], 'sequence' => 1, 'site' => ['id' => 's1', 'reference' => 'CH-1', 'label' => 'Résidence'], 'promoter' => ['name' => 'Bâtir'], 'trade' => 'Maçon', 'period' => ['start' => '2026-11-01', 'end' => '2026-11-07'], 'due_date' => '2026-11-07', 'is_flexible' => false, 'amount_due' => 200000, 'amount_paid' => 50000, 'remaining' => 150000];
    }

    public function test_the_list_translates_filters_for_the_api(): void
    {
        Http::fake([self::API.'/payments*' => Http::response(['data' => [$this->transaction()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['collected' => 100000, 'month' => 100000, 'fees' => 1500, 'failed' => 2], 'operators' => [['id' => 'o1', 'name' => 'Orange Money']]]])]);

        $this->get('/paiements?q=PAY&statut=reussie&nature=acompte&operateur=o1&tri=montant&sens=desc&periode=7j')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Index')
            ->where('transactions.data.0.reference', 'PAY-2026-000001')
            ->where('transactions.data.0.status.tone', 'g')
            ->where('transactions.data.0.fees', 1500)
            ->where('kpis.failed', 2)
            ->where('filterOptions.operateur.0.label', 'Orange Money')
            ->where('can.export', true));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'search=PAY') && str_contains($request->url(), 'status=reussie') && str_contains($request->url(), 'purpose=acompte') && str_contains($request->url(), 'operator_id=o1') && str_contains($request->url(), 'sort=amount') && str_contains($request->url(), 'direction=desc') && str_contains($request->url(), 'date_from=') && str_contains($request->url(), 'per_page=20'));
    }

    public function test_the_collection_page_is_built_from_the_api(): void
    {
        Http::fake([self::API.'/payments/'.self::ID => Http::response(['data' => [...$this->transaction(), 'provider_reference' => 'SIM-1', 'phone' => '07 •• •• 34', 'lines' => [['label' => 'Acompte', 'amount' => 100000]], 'failure' => null, 'invoice' => null, 'allocations' => [['amount' => 100000, 'schedule' => ['id' => self::SCHEDULE, 'reference' => 'ECH-1', 'type' => ['value' => 'acompte', 'label' => 'Acompte']]]], 'receipts' => [['number' => 'REC-1', 'issued_at' => '2026-11-04T09:01:00+00:00', 'url' => null]], 'history' => [['from' => null, 'to' => ['value' => 'initiee', 'label' => 'Initiée'], 'at' => '2026-11-04T09:00:00+00:00']]]])]);

        $this->get('/paiements/'.self::ID)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Show')
            ->where('transaction.providerReference', 'SIM-1')
            ->where('transaction.allocations.0.schedule.reference', 'ECH-1')
            ->where('transaction.receipts.0.issued_at', '2026-11-04')
            ->where('transaction.history.0.label', 'Initiée'));
    }

    public function test_the_schedule_list_translates_filters(): void
    {
        Http::fake([self::API.'/payments/schedules*' => Http::response(['data' => [$this->schedule()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['total' => 1, 'states' => ['en_retard' => 1, 'exigible' => 0, 'partiellement_payee' => 0], 'late_amount' => 150000, 'open_amount' => 150000, 'paid_amount' => 50000]]])]);

        $this->get('/paiements/echeances?etat=en_retard&type=hebdomadaire&tri=echeance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Schedules')
            ->where('schedules.data.0.state.tone', 'r')
            ->where('schedules.data.0.remaining', 150000)
            ->where('kpis.late', 150000)
            ->where('kpis.lateCount', 1));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'state=en_retard') && str_contains($request->url(), 'type=hebdomadaire') && str_contains($request->url(), 'sort=due'));
    }

    public function test_the_schedule_page_shows_the_amount_and_the_days(): void
    {
        Http::fake([self::API.'/payments/schedules/'.self::SCHEDULE => Http::response(['data' => [...$this->schedule(), 'mission_id' => 'm1', 'workers_amount' => 250000, 'promoter_commission_amount' => 20000, 'deposit_deduction_amount' => 70000, 'adjustment_amount' => 0, 'last_reminder_at' => null, 'days' => ['validated' => 4, 'pending' => 1], 'payments' => [['amount' => 50000, 'transaction' => ['id' => self::ID, 'reference' => 'PAY-1', 'operator' => 'Wave', 'status' => ['value' => 'reussie', 'label' => 'Réussie'], 'completed_at' => '2026-11-04T09:01:00+00:00']]], 'installments' => [['sequence' => 2, 'count' => 3, 'amount' => 50000, 'due_date' => '2026-12-01', 'status' => ['value' => 'a_payer', 'label' => 'À payer'], 'is_open' => true]], 'can' => ['remind' => true]]])]);

        $this->get('/paiements/echeances/'.self::SCHEDULE)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Payments/Schedule')
            ->where('schedule.workersAmount', 250000)
            ->where('schedule.days.validated', 4)
            ->where('schedule.payments.0.reference', 'PAY-1')
            ->where('schedule.installments.0.label', 'Versement 2/3')
            ->where('schedule.canRemind', true));
    }

    public function test_exports_are_passed_through(): void
    {
        Http::fake([
            self::API.'/payments/schedules/export*' => Http::response("Référence\nECH-1\n", 200, ['Content-Type' => 'text/csv']),
            self::API.'/payments/export*' => Http::response("Référence\nPAY-1\n", 200, ['Content-Type' => 'text/csv']),
        ]);

        $this->assertStringContainsString('PAY-1', $this->get('/paiements/export?statut=reussie')->assertOk()->getContent());
        $this->assertStringContainsString('ECH-1', $this->get('/paiements/echeances/export')->assertOk()->getContent());
    }

    public function test_a_reminder_shows_the_api_message_or_its_refusal(): void
    {
        Http::fakeSequence(self::API.'/payments/schedules/'.self::SCHEDULE.'/remind')
            ->push(['message' => 'Promoteur relancé.', 'data' => []])
            ->push(['message' => 'Refus', 'errors' => ['payment' => ['Le promoteur a déjà été relancé aujourd’hui pour cette échéance.']]], 422);

        $this->from('/paiements/echeances')->post('/paiements/echeances/'.self::SCHEDULE.'/relance')->assertRedirect('/paiements/echeances')->assertSessionHas('inertia.flash_data.toast', 'Promoteur relancé.');
        $this->from('/paiements/echeances')->post('/paiements/echeances/'.self::SCHEDULE.'/relance')->assertRedirect('/paiements/echeances')->assertSessionHas('inertia.flash_data.toastError', 'Le promoteur a déjà été relancé aujourd’hui pour cette échéance.');
    }

    public function test_old_links_return_to_the_payments(): void
    {
        foreach (['operations/PAY-260918-1702', 'commissions', 'rapports'] as $path) {
            $this->get("/paiements/{$path}")->assertRedirect('/paiements');
        }
    }
}
