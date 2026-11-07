<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Finance branchée sur l’API (api/v1/admin/finance).
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Finance', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['finance.read', 'finance.export']]]);
    }

    public function test_the_overview_passes_the_period(): void
    {
        Http::fake([self::API.'/finance/overview*' => Http::response(['data' => [
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'inflows' => ['missions' => 100, 'subscriptions' => 20, 'total' => 120, 'operator_fees' => 2],
            'outflows' => ['workers' => 50, 'commercials' => 5, 'refunds' => 5, 'total' => 60],
            'net_cash' => 60,
            'commissions' => ['promoter_earned' => 10, 'worker_earned' => 5, 'earned' => 15, 'collected' => 12, 'remaining' => 3],
            'revenue' => 32,
            'receivables' => ['late' => 7, 'open' => 9],
        ]])]);

        $this->get('/finance-reporting?du=2026-10-01&au=2026-10-31')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/Overview')->where('report.revenue', 32)->where('filters.from', '2026-10-01'));

        Http::assertSent(fn (ClientRequest $request) => $request['from'] === '2026-10-01' && $request['to'] === '2026-10-31');
    }

    public function test_the_journal_translates_the_axis(): void
    {
        Http::fake([self::API.'/finance/commissions*' => Http::response(['data' => [['id' => 's1', 'label' => 'Résidence', 'missions' => 2, 'days' => 4, 'y_amount' => 10, 'z_amount' => 5, 'earned' => 15, 'collected' => 12, 'refunded' => 0, 'remaining' => 3]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'period' => ['from' => '2026-11-01', 'to' => '2026-11-07']]])]);

        $this->get('/finance-reporting/commissions?axe=chantier&q=Résidence')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/Commissions')->where('filters.axe', 'chantier')->where('rows.data.0.label', 'Résidence')->where('can.export', true));

        Http::assertSent(fn (ClientRequest $request) => $request['group'] === 'site' && $request['search'] === 'Résidence');
    }

    public function test_the_export_passes_the_filters(): void
    {
        Http::fake([self::API.'/finance/commissions/export*' => Http::response('Mission;Chantier')]);

        $this->get('/finance-reporting/commissions/export?axe=promoteur&du=2026-10-01&au=2026-10-31')->assertOk()->assertHeader('content-disposition', 'attachment; filename="commissions.csv"');

        Http::assertSent(fn (ClientRequest $request) => $request['group'] === 'promoter' && $request['from'] === '2026-10-01');
    }

    public function test_old_pages_lead_to_the_new_screens(): void
    {
        $this->get('/finance-reporting/chantiers')->assertRedirect('/finance-reporting/commissions?axe=chantier');
        $this->get('/finance-reporting/tresorerie')->assertRedirect('/finance-reporting');
        $this->get('/finance-reporting/clotures')->assertRedirect('/finance-reporting');
    }
}
