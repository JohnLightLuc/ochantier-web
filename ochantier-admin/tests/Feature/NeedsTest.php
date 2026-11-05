<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Besoins branchées sur l’API (api/v1/admin/offers) : liste, export, fiche, onglets et décisions.
 */
class NeedsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000f1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['offers.read', 'offers.update', 'offers.validate', 'offers.export']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => self::ID, 'reference' => 'OFF-2026-000001', 'title' => 'Équipe gros œuvre', 'type' => ['value' => 'ponctuelle', 'label' => 'Ponctuelle'], 'urgency' => ['value' => 'normale', 'label' => 'Normale'],
            'status' => ['value' => 'publiee', 'label' => 'Publiée'], 'site' => ['id' => 's1', 'reference' => 'CHT-1', 'label' => 'Résidence Les Palmiers', 'commune' => 'Cocody'], 'promoter' => ['id' => 'p1', 'name' => 'Bâtir Afrique'],
            'main_trade' => 'Maçon', 'trades_count' => 2, 'coverage' => ['required' => 4, 'assigned' => 1, 'percent' => 25], 'proposal_pending' => true, 'deposit_expected' => false,
            'start_date' => '2026-11-10', 'end_date' => '2026-12-10', 'application_deadline' => '2026-11-08T23:59:59+00:00', 'submitted_at' => '2026-11-01T10:00:00+00:00', ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        return [...$this->row(), 'description' => null, 'deliverables' => null, 'visibility' => null, 'extension_count' => 1, 'cancellation_reason' => null, 'suspension_reason' => null, 'engagement' => null,
            'needs' => [['id' => 'n1', 'reference' => 'BES-1', 'trade' => 'Maçon', 'headcount' => 3, 'assigned' => 1, 'level' => null, 'min_experience_years' => 3, 'start_date' => '2026-11-10', 'end_date' => '2026-12-10', 'duration_days' => 30, 'daily_budget' => 20000, 'payment_frequency' => 'journalier', 'status' => ['value' => 'matching', 'label' => 'Matching']]],
            'can' => ['suspend' => true, 'resume' => false, 'extend' => true, 'cancel' => true, 'close' => false], ...$overrides];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function listResponse(array $rows): array
    {
        return ['data' => $rows, 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($rows),
            'counts' => ['total' => 5, 'statuses' => ['soumise' => 1, 'en_controle' => 0, 'a_corriger' => 0, 'publiee' => 2, 'suspendue' => 1, 'pourvue' => 1, 'cloturee' => 0, 'annulee' => 0], 'todo' => ['a_matcher' => 1, 'proposition' => 1, 'acompte' => 0, 'incomplet' => 1, 'expires' => 0]],
            'trades' => [['id' => 't1', 'label' => 'Maçon']], 'communes' => [['id' => 'c1', 'name' => 'Cocody']]]];
    }

    public function test_the_list_translates_the_filters_and_shows_the_api_rows(): void
    {
        Http::fake([self::API.'/offers*' => Http::response($this->listResponse([$this->row()]))]);

        $this->get('/besoins?q=palmiers&statut=publiee&metier=t1&localisation=c1&type=ponctuelle&urgence=haute&rapide=proposition&tri=couverture&sens=desc')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Needs/Index')->where('offers.data.0.reference', 'OFF-2026-000001')->where('offers.data.0.percent', 25)->where('offers.data.0.deadline', '2026-11-08')->where('kpis', ['total' => 5, 'open' => 3, 'filled' => 1, 'suspended' => 1])->where('todo.proposition', 1));

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=palmiers') && str_contains($r->url(), 'status=publiee') && str_contains($r->url(), 'trade_id=t1') && str_contains($r->url(), 'offer_type=ponctuelle') && str_contains($r->url(), 'todo=proposition') && str_contains($r->url(), 'sort=coverage'));
    }

    public function test_the_fiche_its_tabs_and_old_links(): void
    {
        Http::fake([
            self::API.'/offers/'.self::ID.'/workers' => Http::response(['data' => ['proposals' => [['id' => 'p1', 'reference' => 'PR-1', 'sequence' => 1, 'status' => ['value' => 'a_valider', 'label' => 'À valider'], 'requested' => 4, 'proposed' => 3, 'is_partial' => true, 'total_amount' => 1000, 'deposit_amount' => 300, 'published_at' => '2026-11-02T10:00:00+00:00', 'decided_at' => null, 'refusal' => null]], 'assigned' => [['id' => 'a1', 'worker' => ['id' => 'w1', 'full_name' => 'Jean Kouassi'], 'trade' => 'Maçon', 'status' => ['value' => 'active', 'label' => 'Active'], 'start_date' => '2026-11-10', 'end_date' => '2026-12-10']]]]),
            self::API.'/offers/'.self::ID.'/documents' => Http::response(['data' => []]),
            self::API.'/offers/'.self::ID.'/history*' => Http::response(['data' => [['id' => 'audit:1', 'origin' => 'admin', 'author' => 'Awa Koné', 'what' => 'a suspendu le besoin', 'detail' => 'Vérification.', 'from' => null, 'to' => null, 'at' => '2026-11-04T10:00:00+00:00']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1]]),
            self::API.'/offers/'.self::ID => Http::response(['data' => $this->detail()]),
        ]);
        $page = '/besoins/'.self::ID;

        $this->get($page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Needs/Show')->where('offer.needs.0.budget', 20000)->where('kpis.0.value', '1 / 4'));
        $this->get("{$page}/ouvriers")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.proposals.0.status.tone', 'a')->where('relations.assigned.0.name', 'Jean Kouassi'));
        $this->get("{$page}/documents")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.kind', 'documents'));
        $this->get("{$page}/historique")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.rows.0.action', 'a suspendu le besoin'));

        $this->get("{$page}/metiers")->assertRedirect($page);
        $this->get('/besoins/nouveau-besoin')->assertRedirect('/besoins');
        $this->get('/besoins/ancien-besoin/metiers')->assertRedirect('/besoins?q=ancien%20besoin');
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/offers/'.self::ID.'/suspension' => Http::response(['message' => 'Besoin suspendu : la recherche est arrêtée.']),
            self::API.'/offers/'.self::ID.'/extension' => Http::response(['message' => 'Date limite prolongée.']),
            self::API.'/offers/'.self::ID.'/cancellation' => Http::response(['message' => 'Refus.', 'errors' => ['offer' => ['Le promoteur a accepté une proposition : un acompte est attendu ou payé.']]], 422),
            self::API.'/offers/'.self::ID.'/closure' => Http::response(['message' => 'Besoin clôturé.']),
        ]);
        $page = '/besoins/'.self::ID;

        $this->from($page)->post("{$page}/suspension", ['category' => 'Litige', 'reason' => 'Contrôle en cours.'])->assertSessionHas('inertia.flash_data.toast', 'Besoin suspendu : la recherche est arrêtée.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/suspension') && $r['reason'] === 'Litige · Contrôle en cours.');
        $this->from($page)->post("{$page}/prolongation", ['deadline' => '2026-12-01'])->assertSessionHas('inertia.flash_data.toast', 'Date limite prolongée.');
        $this->from($page)->post("{$page}/annulation", ['category' => 'Autre', 'reason' => 'Doublon.'])->assertSessionHas('inertia.flash_data.toastError', 'Le promoteur a accepté une proposition : un acompte est attendu ou payé.');
        $this->from($page)->post("{$page}/cloture", ['category' => 'Autre', 'reason' => 'Équipe suffisante.'])->assertSessionHas('inertia.flash_data.toast', 'Besoin clôturé.');
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['offers.read']);
        Http::fake([self::API.'/offers*' => Http::response($this->listResponse([]))]);

        $this->get('/besoins')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['update' => false, 'validate' => false, 'export' => false]));
    }
}
