<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Abonnements branchés sur l’API (api/v1/admin/subscriptions et subscription-plans).
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const SUBSCRIPTION = '01a16ab6-0000-7000-8000-0000000000f1';

    private const PLAN = '01a16ab6-0000-7000-8000-0000000000f2';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Finance', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['subscriptions.read', 'subscriptions.update', 'subscriptions.export']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => self::SUBSCRIPTION,
            'reference' => 'ABO-2026-000001',
            'promoter' => ['id' => '01a16ab6-0000-7000-8000-0000000000f3', 'name' => 'BTP Plateau', 'owner' => 'Yao Koffi'],
            'plan' => ['code' => 'equipe', 'name' => 'Équipe', 'price' => 15000, 'max_users' => 5],
            'pending_plan' => ['code' => 'pro', 'name' => 'Pro', 'effective_at' => '2026-12-01'],
            'status' => ['value' => 'en_retard', 'label' => 'En retard'],
            'seats_used' => 2,
            'started_at' => '2026-10-01',
            'period_end' => '2026-11-30',
            'next_due_date' => '2026-12-01',
            'auto_renew' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $code = 'equipe'): array
    {
        return ['id' => self::PLAN, 'code' => $code, 'name' => ucfirst($code), 'subtitle' => 'Jusqu’à 5 utilisateurs', 'price' => 15000, 'max_users' => 5, 'features' => ['Rapports'], 'is_featured' => true, 'is_active' => true, 'is_free' => false, 'subscribers' => 1, 'monthly_revenue' => 15000];
    }

    public function test_the_list_shows_subscribers_and_plans(): void
    {
        Http::fake([
            self::API.'/subscriptions?*' => Http::response(['data' => [$this->row()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'plans' => [['code' => 'equipe', 'name' => 'Équipe']], 'counts' => ['paying' => 1, 'promoters' => 4, 'monthly_revenue' => 15000, 'statuses' => ['en_retard' => 1, 'suspendue' => 0]]]]),
            self::API.'/subscription-plans' => Http::response(['data' => [$this->plan()]]),
        ]);

        $this->get('/abonnements?statut=en_retard&q=BTP&onglet=formules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Index')
                ->where('tab', 'formules')
                ->where('filters.statut', 'en_retard')
                ->where('kpis.late', 1)
                ->where('subscriptions.data.0.status.tone', 'r')
                ->where('subscriptions.data.0.pending.name', 'Pro')
                ->where('plans.0.popular', true)
                ->where('can.update', true));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/subscriptions?') && $request['status'] === 'en_retard' && $request['search'] === 'BTP');
    }

    public function test_the_export_passes_the_filters(): void
    {
        Http::fake([self::API.'/subscriptions/export*' => Http::response('Référence;Promoteur')]);

        $this->get('/abonnements/export?formule=pro')->assertOk()->assertHeader('content-disposition', 'attachment; filename="abonnements.csv"');

        Http::assertSent(fn (ClientRequest $request) => $request['plan'] === 'pro');
    }

    public function test_the_detail_page_offers_the_other_active_paid_plans(): void
    {
        Http::fake([
            self::API.'/subscriptions/'.self::SUBSCRIPTION => Http::response(['data' => [
                ...$this->row(),
                'contact' => ['phone' => '0712345634', 'email' => null],
                'period_start' => '2026-11-01',
                'previous_plan' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'payment_operator' => 'Wave',
                'users' => [['id' => '1', 'name' => 'Yao Koffi', 'role' => 'Propriétaire', 'status' => 'Actif']],
                'invoices' => [['id' => '1', 'reference' => 'FAB-2026-000001', 'label' => 'Abonnement Équipe', 'amount' => 15000, 'date_label' => '1 nov. 2026', 'status' => ['value' => 'payee', 'label' => 'Payée'], 'receipt_url' => null]],
                'history' => [['id' => '1', 'date' => '2026-11-01T10:00:00+00:00', 'reason' => 'Souscription', 'status' => 'Active', 'actor' => 'Promoteur']],
                'can' => ['remind' => true, 'change_plan' => false, 'cancel_plan_change' => true, 'suspend' => true, 'reactivate' => false, 'terminate' => true, 'resume' => false],
            ]]),
            self::API.'/subscription-plans' => Http::response(['data' => [$this->plan(), $this->plan('pro'), [...$this->plan('solo'), 'is_free' => true], [...$this->plan('ancienne'), 'is_active' => false]]]),
        ]);

        $this->get('/abonnements/'.self::SUBSCRIPTION)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subscriptions/Show')
                ->where('subscription.actions.remind', true)
                ->where('subscription.invoices.0.status.tone', 'g')
                ->has('plans', 1)
                ->where('plans.0.code', 'pro'));
    }

    public function test_actions_reach_the_api_and_refusals_become_messages(): void
    {
        Http::fake([
            self::API.'/subscriptions/'.self::SUBSCRIPTION.'/suspension' => Http::response(['message' => 'Abonnement suspendu.', 'data' => []]),
            self::API.'/subscriptions/'.self::SUBSCRIPTION.'/plan-change' => Http::sequence()
                ->push(['message' => 'Formule refusée.', 'errors' => ['subscription' => ['Une facture est en attente.']]], 422)
                ->push(['message' => 'Changement de formule annulé.', 'data' => []]),
            self::API.'/subscriptions/'.self::SUBSCRIPTION.'/termination' => Http::response(['message' => 'Résiliation annulée.', 'data' => []]),
        ]);
        $base = '/abonnements/'.self::SUBSCRIPTION;

        $this->from($base)->post("{$base}/suspension", ['reason' => 'Fraude suspectée'])->assertRedirect($base);
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/suspension') && $request['reason'] === 'Fraude suspectée');

        $this->from($base)->post("{$base}/formule", ['plan_code' => 'pro'])->assertRedirect($base)->assertSessionHasNoErrors();
        $this->from($base)->delete("{$base}/formule")->assertRedirect($base);
        $this->from($base)->delete("{$base}/resiliation")->assertRedirect($base);

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/termination'));
    }

    public function test_plans_send_one_feature_per_line(): void
    {
        Http::fake([
            self::API.'/subscription-plans' => Http::response(['message' => 'Formule créée.', 'data' => []], 201),
            self::API.'/subscription-plans/'.self::PLAN.'/retire' => Http::response(['message' => 'Formule retirée.', 'data' => []]),
        ]);

        $this->from('/abonnements')->post('/abonnements/formules', ['name' => 'Chantier', 'price_amount' => '25000', 'max_users' => '8', 'is_featured' => '0', 'features' => "Rapports\n\n Support "])->assertRedirect('/abonnements');
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/subscription-plans') && $request['features'] === ['Rapports', 'Support']);

        $this->from('/abonnements')->post('/abonnements/formules/'.self::PLAN.'/retrait')->assertRedirect('/abonnements');
    }

    public function test_old_links_return_to_the_list(): void
    {
        $this->get('/abonnements/btp-plateau')->assertRedirect('/abonnements');
    }
}
