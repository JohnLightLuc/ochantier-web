<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Matching branchées sur l’API (api/v1/admin/matching) : file des besoins, lancement et résultats.
 */
class MatchingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000e1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['matching.read', 'matching.create'], 'must_change_password' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(): array
    {
        return ['id' => self::ID, 'reference' => 'OFF-1', 'title' => 'Équipe gros œuvre', 'type' => ['value' => 'ponctuelle', 'label' => 'Ponctuelle'], 'urgency' => ['value' => 'normale', 'label' => 'Normale'], 'status' => ['value' => 'soumise', 'label' => 'Soumise'],
            'site' => ['id' => 's1', 'reference' => 'CHT-1', 'label' => 'Résidence Les Palmiers', 'commune' => 'Cocody'], 'promoter' => ['id' => 'p1', 'name' => 'Bâtir Afrique'], 'main_trade' => 'Maçon', 'trades_count' => 1, 'coverage' => ['required' => 2, 'assigned' => 0, 'percent' => 0],
            'proposal_pending' => false, 'deposit_expected' => false, 'start_date' => '2026-11-10', 'end_date' => '2026-12-10', 'application_deadline' => null, 'submitted_at' => null];
    }

    public function test_the_queue_shows_the_open_offers(): void
    {
        Http::fake([self::API.'/matching/queue*' => Http::response(['data' => [[...$this->offer(), 'last_run_at' => '2026-11-04T10:00:00+00:00']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['todo' => ['a_matcher' => 2, 'proposition' => 1, 'acompte' => 0, 'incomplet' => 0, 'expires' => 0]], 'trades' => [['id' => 't1', 'label' => 'Maçon']], 'communes' => []]])]);

        $this->get('/matching?q=palmiers&metier=t1')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Matching/Index')->where('offers.data.0.lastRunAt', '2026-11-04')->where('kpis.toMatch', 2));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=palmiers') && str_contains($r->url(), 'trade_id=t1'));
    }

    public function test_the_results_explain_the_score_and_the_run_reaches_the_api(): void
    {
        Http::fake([
            self::API.'/matching/offers/'.self::ID.'/run' => Http::sequence()->push(['message' => 'Matching terminé.'])->push(['message' => 'Refus.', 'errors' => ['offer' => ['Une proposition attend la décision du promoteur.']]], 422),
            self::API.'/matching/offers/'.self::ID => Http::response(['data' => ['offer' => $this->offer(), 'run_refusal' => null, 'needs' => [[
                'id' => 'n1', 'reference' => 'BES-1', 'trade' => 'Maçon', 'specialty' => null, 'headcount' => 2, 'min_experience_years' => 3, 'daily_budget' => 25000, 'status' => ['value' => 'matching', 'label' => 'Matching'],
                'last_run' => ['at' => '2026-11-04T10:00:00+00:00', 'by' => 'Awa Koné', 'type' => 'initial', 'stale' => false],
                'suggestions' => [['id' => 's1', 'rank' => 1, 'score' => 87.5, 'level' => 'excellent', 'requested' => true, 'status' => ['value' => 'suggere', 'label' => 'Suggéré'], 'worker' => ['id' => 'w1', 'reference' => 'OUV-1', 'full_name' => 'Jean Kouassi', 'commune' => 'Cocody', 'rating' => 4.7, 'experience_years' => 5], 'distance_km' => 3.2, 'worker_daily_rate' => 22000, 'promoter_daily_rate' => 24200, 'criteria' => [['key' => 'metier', 'label' => 'Métier', 'weight' => 25, 'value' => 100, 'contribution' => 25, 'detail' => 'Métier principal']], 'warnings' => []]],
                'exclusions' => [['id' => 'e1', 'worker' => ['id' => 'w2', 'full_name' => 'Ali Touré', 'reference' => 'OUV-2'], 'nature' => ['value' => 'reglementaire', 'label' => 'Réglementaire'], 'criterion' => 'planning', 'comment' => 'Libre sur la période : Déjà engagé sur ces dates']],
            ]]]]),
        ]);
        $page = '/matching/besoins/'.self::ID;

        $this->get($page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Matching/Offer')->where('needs.0.suggestions.0.level.label', 'Excellent')->where('needs.0.suggestions.0.status.tone', 'b')->where('needs.0.exclusions.0.absolute', true)->where('can.run', true));

        $this->from($page)->post("{$page}/lancement")->assertSessionHas('inertia.flash_data.toast', 'Matching terminé.');
        $this->from($page)->post("{$page}/lancement")->assertSessionHas('inertia.flash_data.toastError', 'Une proposition attend la décision du promoteur.');
    }

    public function test_old_addresses_lead_to_the_queue(): void
    {
        $this->get('/matching/resultats')->assertRedirect('/matching');
        $this->get('/matching/besoins/12/comparaison')->assertRedirect('/matching');
    }
}
