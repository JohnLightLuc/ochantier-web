<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Rapports branchés sur l’API (api/v1/admin/reports).
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Finance', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['reports.read', 'reports.export']]]);
    }

    public function test_the_overview_shows_the_report_list_and_months(): void
    {
        Http::fake([self::API.'/reports' => Http::response(['data' => ['kinds' => [['kind' => 'missions', 'label' => 'Rapport des missions']], 'indicators' => ['sites_active' => 1, 'sites_done' => 2, 'occupation' => 40, 'rating' => 4.2], 'months' => [['month' => '2026-11', 'label' => 'Nov. 26', 'received' => 100, 'paid_out' => 50, 'collected' => 10, 'revenue' => 12]]]])]);

        $this->get('/rapports')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Analytics/Overview')->where('report.indicators.occupation', 40)->has('report.months', 1));
    }

    public function test_a_report_is_paginated_and_passes_the_period(): void
    {
        $rows = array_map(fn (int $i) => ['reference' => "M-{$i}"], range(1, 25));
        Http::fake([self::API.'/reports/missions*' => Http::response(['data' => ['kind' => 'missions', 'title' => 'Rapport des missions', 'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'indicators' => [['label' => 'Missions', 'value' => 25]], 'columns' => [['key' => 'reference', 'label' => 'Mission']], 'rows' => $rows]])]);

        $this->get('/rapports/missions?du=2026-10-01&au=2026-10-31&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Analytics/Report')->has('report.rows', 5)->where('report.rows.0.reference', 'M-21')->where('pagination.total', 25)->where('can.export', true));

        Http::assertSent(fn (ClientRequest $request) => $request['from'] === '2026-10-01' && $request['to'] === '2026-10-31');
    }

    public function test_the_export_passes_the_period(): void
    {
        Http::fake([self::API.'/reports/ouvriers/export*' => Http::response('Référence;Ouvrier')]);

        $this->get('/rapports/ouvriers/export?du=2026-10-01')->assertOk()->assertHeader('content-disposition', 'attachment; filename="rapport-ouvriers.csv"');

        Http::assertSent(fn (ClientRequest $request) => $request['from'] === '2026-10-01');
    }

    public function test_old_pages_lead_to_the_overview(): void
    {
        $this->get('/rapports/financier/details')->assertRedirect('/rapports');
        $this->get('/rapports/chantiers/residence-les-palmiers')->assertRedirect('/rapports');
    }
}
