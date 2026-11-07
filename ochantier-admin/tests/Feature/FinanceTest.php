<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
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

    public function test_reconciliations_list_runs_and_upload_a_statement(): void
    {
        $run = ['id' => '01a16ab6-0000-7000-8000-0000000000d1', 'provider' => 'orange', 'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'status' => ['value' => 'termine', 'label' => 'Terminé'], 'summary' => ['file_rows' => 3, 'matched' => 2, 'matched_amount' => 5000, 'file_amount' => 6000, 'discrepancies' => 1], 'open_items' => 1, 'by' => 'Awa Finance', 'imported_at' => '2026-11-01T10:00:00+00:00'];
        Http::fake([
            self::API.'/finance/reconciliations?*' => Http::response(['data' => [$run], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['runs' => 1, 'open_items' => 1, 'unreconciled' => 2], 'operators' => [['id' => 'o1', 'name' => 'Orange Money', 'provider' => 'orange']]]]),
            self::API.'/finance/reconciliations' => Http::sequence()
                ->push(['message' => 'Refusé.', 'errors' => ['reconciliation' => ['Des écarts restent à traiter.']]], 422)
                ->push(['message' => 'Refusé.', 'errors' => ['file' => ['Ligne 3 illisible.']]], 422)
                ->push(['message' => '2 opération(s) rapprochée(s).', 'data' => []], 201),
        ]);

        $this->get('/finance-reporting/rapprochements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Finance/Reconciliations')->where('runs.data.0.provider', 'Orange')->where('kpis.open_items', 1)->where('operators.0.label', 'Orange Money'));

        $body = fn () => ['operator_id' => 'o1', 'from' => '2026-10-01', 'to' => '2026-10-31', 'file' => UploadedFile::fake()->createWithContent('releve.csv', "reference;montant\nA;1\n")];
        $url = '/finance-reporting/rapprochements';

        $this->from($url)->post($url, $body())->assertRedirect($url)->assertSessionHasNoErrors();
        $this->from($url)->post($url, $body())->assertRedirect($url)->assertSessionHasErrors('file');
        $this->from($url)->post($url, $body())->assertRedirect($url)->assertSessionHasNoErrors();

        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/finance/reconciliations') && $request->isMultipart());
    }

    public function test_a_gap_is_decided_with_a_reason(): void
    {
        $run = '01a16ab6-0000-7000-8000-0000000000d1';
        $item = '01a16ab6-0000-7000-8000-0000000000d2';
        Http::fake([self::API."/finance/reconciliations/{$run}/items/{$item}/accept" => Http::response(['message' => 'Écart accepté.', 'data' => []])]);
        $base = "/finance-reporting/rapprochements/{$run}";

        $this->from($base)->post("{$base}/ecarts/{$item}/acceptation", ['reason' => 'Opération de test'])->assertRedirect($base);

        Http::assertSent(fn (ClientRequest $request) => $request['reason'] === 'Opération de test');
    }

    public function test_old_pages_lead_to_the_new_screens(): void
    {
        $this->get('/finance-reporting/chantiers')->assertRedirect('/finance-reporting/commissions?axe=chantier');
        $this->get('/finance-reporting/tresorerie')->assertRedirect('/finance-reporting');
        $this->get('/finance-reporting/clotures')->assertRedirect('/finance-reporting');
    }
}
