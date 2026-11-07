<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tableau de bord branché sur l’API (api/v1/admin/dashboard).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private function signIn(): void
    {
        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'N Yao', 'email' => 'n.yao@ochantier.ci', 'roles' => [], 'permissions' => ['dashboard.read']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        return [
            'kpis' => [['key' => 'sites', 'value' => 3], ['key' => 'receivables', 'value' => 1250000]],
            'activity' => ['range' => '7j', 'from' => '2026-11-01', 'to' => '2026-11-07', 'points' => [['date' => '2026-11-01', 'active' => 2, 'created' => 1]]],
            'priorities' => array_map(fn (string $key) => ['key' => $key, 'count' => 2, 'priority' => 'Élevé'], ['replacements', 'claims', 'batches', 'refunds', 'workers']),
            'recent_sites' => [['id' => 's1', 'name' => 'Résidence', 'promoter' => ['id' => 'p1', 'name' => 'BTP Plateau'], 'location' => 'Cocody', 'elapsed' => 40, 'workers' => ['assigned' => 2, 'required' => 3], 'status' => ['value' => 'en_cours', 'label' => 'En cours'], 'late' => true, 'start' => '2026-10-01', 'end' => '2026-10-31', 'type' => null]],
            'site_distribution' => ['running' => 2, 'late' => 1, 'suspended' => 0],
            'matching' => null,
            'finance' => ['range' => '7j', 'points' => [['from' => '2026-11-01T00:00:00+00:00', 'collected' => 2500000, 'paid_out' => 1000000]], 'pending' => 500000],
            'feed' => [['kind' => 'payment', 'id' => 't1', 'target' => 'PAY-1', 'amount' => 5000, 'at' => '2026-11-07T08:00:00+00:00'], ['kind' => 'dispute', 'id' => 'd1', 'target' => 'LIT-1', 'at' => null]],
        ];
    }

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/connexion');
    }

    public function test_the_dashboard_shows_real_figures_and_hides_forbidden_blocks(): void
    {
        $this->signIn();
        Http::fake([
            self::API.'/dashboard?*' => Http::response(['data' => $this->dashboard()]),
            self::API.'/dashboard/badges' => Http::response(['data' => ['badges' => ['avis' => 4], 'priorities' => [['key' => 'disputes', 'count' => 4, 'priority' => 'Moyen']]]]),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('kpis', 2)
                ->where('kpis.0.label', 'Chantiers actifs')
                ->where('kpis.1.unit', 'FCFA')
                ->where('priorities.total', 8)
                ->where('priorities.totalAll', 10)
                ->has('priorities.more', 1)
                ->where('recentSites.0.status', 'En retard')
                ->where('siteDistribution.total', 3)
                ->where('matching', null)
                ->where('finance.collected.0', 2.5)
                ->where('finance.pending', 0.5)
                ->where('feed.1.href', '/avis-litiges/litiges/d1')
                ->where('navigation', fn ($navigation) => collect($navigation)->firstWhere('key', 'avis')['badge'] === 4)
                ->where('notifications.0.title', '4 litiges en cours')
                ->where('notifications.0.href', '/avis-litiges/litiges'));
    }

    public function test_ranges_are_passed_and_unknown_ones_fall_back(): void
    {
        $this->signIn();
        Http::fake([self::API.'/dashboard?*' => Http::response(['data' => [...$this->dashboard(), 'activity' => ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-21', 'points' => []]]]), self::API.'/dashboard/badges' => Http::response(['data' => ['badges' => [], 'priorities' => []]])]);

        $this->get('/?periode=custom&du=2026-09-01&au=2026-09-21&flux=auj')->assertOk();
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/dashboard?') && $request['activity'] === 'custom' && $request['from'] === '2026-09-01' && $request['finance'] === 'auj');

        $this->get('/?periode=nimporte&flux=quoi')->assertOk();
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/dashboard?') && $request['activity'] === '7j' && $request['finance'] === '7j');
    }

    public function test_the_header_counters_never_block_a_page_and_are_cached(): void
    {
        $this->signIn();
        Http::fake([
            self::API.'/dashboard?*' => Http::response(['data' => $this->dashboard()]),
            self::API.'/dashboard/badges' => Http::sequence()->pushStatus(500)->push(['data' => ['badges' => ['avis' => 1], 'priorities' => []]]),
        ]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('notifications', []));
        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        Http::assertSentCount(5);
    }
}
