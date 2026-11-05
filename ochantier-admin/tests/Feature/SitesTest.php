<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Chantiers branchées sur l’API (api/v1/admin/sites) : liste, export, fiche et décisions.
 */
class SitesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000b1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['sites.read', 'sites.update', 'sites.validate', 'sites.export']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store([
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => self::ID, 'reference' => 'CHT-2026-000001', 'label' => 'Résidence Les Palmiers', 'promoter' => ['id' => 'p1', 'name' => 'Bâtir Afrique'],
            'commune' => ['id' => 'c1', 'name' => 'Cocody'], 'construction_type' => ['id' => 't1', 'label' => 'Villa'], 'work_type' => ['value' => 'construction', 'label' => 'Construction'],
            'priority' => ['value' => 'normale', 'label' => 'Normale'], 'status' => ['value' => 'en_cours', 'label' => 'En cours'], 'planned_start_date' => '2026-10-01', 'planned_end_date' => '2026-12-01',
            'staffing' => ['required' => 5, 'assigned' => 3], 'is_late' => false, 'open_incidents_count' => 1, 'pending_days_count' => 2, 'submitted_at' => '2026-09-20T10:00:00+00:00', 'archived_at' => null,
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        return [
            ...$this->row(), 'internal_reference' => null, 'description' => 'Villa de 3 pièces', 'address' => 'Riviera 3', 'neighborhood' => ['id' => 'n1', 'name' => 'Riviera'], 'access_instructions' => null,
            'location' => null, 'geofence_radius_m' => 200, 'planned_duration_days' => 60, 'work_hours' => ['start' => '08:00:00', 'end' => '17:00:00'],
            'contact' => ['full_name' => 'Jean Kouassi', 'phone' => '+225 07', 'email' => null], 'responsible' => null,
            'needs' => [['id' => 'n1', 'trade' => 'Maçon', 'headcount' => 3, 'start_date' => '2026-10-01', 'status' => ['value' => 'en_cours', 'label' => 'En cours']]],
            'suspension_reason' => null, 'cancellation_reason' => null, 'closed_at' => null, 'closed_by' => null,
            'can' => ['suspend' => true, 'resume' => false, 'cancel' => false, 'close' => true, 'archive' => false],
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function listResponse(array $rows): array
    {
        return ['data' => $rows, 'meta' => [
            'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($rows),
            'counts' => ['total' => 4, 'statuses' => [], 'active' => 2, 'searching' => 1, 'archived' => 1, 'todo' => ['retard' => 1, 'effectif' => 1, 'incidents' => 1, 'cloturer' => 0, 'pointages' => 1]],
            'communes' => [['id' => 'c1', 'name' => 'Cocody']], 'construction_types' => [['id' => 't1', 'label' => 'Villa']],
        ]];
    }

    public function test_the_list_translates_the_filters_and_shows_the_api_rows(): void
    {
        Http::fake([self::API.'/sites*' => Http::response($this->listResponse([$this->row()]))]);

        $this->get('/chantiers?q=palmiers&statut=en_cours&type=t1&localisation=c1&periode=mois&priorite=haute&rapide=retard&tri=fin&sens=desc')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Sites/Index')->where('sites.data.0.name', 'Résidence Les Palmiers')->where('sites.data.0.status.tone', 'g')->where('sites.data.0.pendingDays', 2)->where('kpis.late', 1)->where('todo.pointages', 1)->where('can.validate', true));

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=palmiers') && str_contains($r->url(), 'status=en_cours') && str_contains($r->url(), 'construction_type_id=t1') && str_contains($r->url(), 'period=mois') && str_contains($r->url(), 'todo=retard') && str_contains($r->url(), 'sort=end') && str_contains($r->url(), 'direction=desc'));

        $this->get('/chantiers?statut=archives')->assertOk();
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'archived=1'));

        $this->get('/chantiers?filtre=En%20cours')->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.statut', 'en_cours'));
    }

    public function test_the_export_relays_the_api_csv(): void
    {
        Http::fake([self::API.'/sites/export*' => Http::response("Référence;Chantier\n", 200, ['Content-Disposition' => 'attachment; filename="chantiers-2026-11-05.csv"'])]);

        $this->get('/chantiers/export?statut=en_cours')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="chantiers-2026-11-05.csv"');
    }

    public function test_the_fiche_shows_the_site_and_a_suspended_banner(): void
    {
        Http::fake([self::API.'/sites/'.self::ID => Http::response(['data' => $this->detail(['status' => ['value' => 'suspendu', 'label' => 'Suspendu'], 'suspension_reason' => 'Arrêt de sécurité.', 'can' => ['suspend' => false, 'resume' => true, 'cancel' => false, 'close' => true, 'archive' => false]])])]);

        $this->get('/chantiers/'.self::ID)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Sites/Show')->where('site.reference', 'CHT-2026-000001')->where('site.workHours', '08:00 – 17:00')->where('site.needs.0.trade', 'Maçon')->where('kpis.0.value', '3 / 5')->where('banner.title', 'Chantier suspendu.'));
    }

    public function test_old_links_lead_to_the_search_or_the_fiche(): void
    {
        $this->get('/chantiers/residence-les-palmiers/equipe')->assertRedirect('/chantiers?q=Residence%20Les%20Palmiers');
        $this->get('/chantiers/'.self::ID.'/inconnu')->assertRedirect('/chantiers/'.self::ID);
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/sites/'.self::ID.'/suspension' => Http::response(['message' => 'Chantier suspendu : les pointages sont bloqués.']),
            self::API.'/sites/'.self::ID.'/closure' => Http::response(['message' => '2 journées de pointage à valider avant la clôture.', 'errors' => ['site' => ['2 journées de pointage à valider avant la clôture.']]], 422),
            self::API.'/sites/'.self::ID.'/archive' => Http::response(['message' => 'Chantier archivé.']),
        ]);
        $page = '/chantiers/'.self::ID;

        $this->from($page)->post("{$page}/suspension", ['category' => 'Sécurité', 'reason' => 'Échafaudage instable.'])->assertSessionHas('inertia.flash_data.toast', 'Chantier suspendu : les pointages sont bloqués.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/suspension') && $r['reason'] === 'Sécurité · Échafaudage instable.');

        $this->from($page)->delete("{$page}/suspension")->assertSessionHas('inertia.flash_data.toast');
        $this->from($page)->post("{$page}/cloture")->assertSessionHas('inertia.flash_data.toastError', '2 journées de pointage à valider avant la clôture.');
        $this->from($page)->post("{$page}/archivage")->assertSessionHas('inertia.flash_data.toast', 'Chantier archivé.');
    }

    public function test_the_tabs_read_only_their_own_data_and_incidents_reach_the_api(): void
    {
        $incident = '01a16ab6-0000-7000-8000-0000000000c9';

        Http::fake([
            self::API.'/sites/'.self::ID.'/team' => Http::response(['data' => [
                'members' => [['assignment_id' => 'a1', 'worker_id' => 'w1', 'name' => 'Jean Kouassi', 'trade' => ['label' => 'Maçon'], 'role' => 'Maçon', 'since' => '2026-10-01', 'daily_rate' => 22000, 'status' => ['label' => 'Active'], 'replacement' => null, 'replaces' => null]],
                'today' => ['label' => 'Aujourd’hui', 'is_open' => true, 'closed_message' => null, 'stats' => ['present' => 1, 'total' => 3, 'absent' => 0, 'pending' => 2], 'validated' => false],
                'days' => [['date' => '2026-11-04', 'label' => 'mer. 4 nov.', 'validated' => true, 'present' => 3, 'total' => 3, 'presence_rate' => 100]],
            ]]),
            self::API.'/sites/'.self::ID.'/incidents/'.$incident.'/resolve' => Http::response(['message' => 'Incident résolu.']),
            self::API.'/sites/'.self::ID.'/incidents' => Http::response(['message' => 'Incident enregistré.', 'data' => [['id' => $incident, 'reference' => 'INC-2026-00001', 'type' => ['value' => 'securite', 'label' => 'Sécurité'], 'severity' => ['value' => 'grave', 'label' => 'Grave'], 'status' => ['value' => 'ouvert', 'label' => 'Ouvert'], 'description' => 'Chute.', 'resolution' => null, 'resolved_by' => null, 'occurred_at' => '2026-11-04T10:00:00+00:00', 'resolved_at' => null]], 'meta' => ['types' => [['value' => 'securite', 'label' => 'Sécurité']], 'severities' => [['value' => 'grave', 'label' => 'Grave']]]]),
            self::API.'/sites/'.self::ID.'/documents' => Http::response(['data' => [['id' => 'd1', 'type' => ['label' => 'Plan architectural'], 'version' => 1, 'created_at' => '2026-10-01T10:00:00+00:00', 'files' => [['name' => 'plan.pdf', 'url' => 'https://api.test/media/1']]]]]),
            self::API.'/sites/'.self::ID.'/history*' => Http::response(['data' => [['id' => 'audit:1', 'origin' => 'admin', 'author' => 'Awa Koné', 'what' => 'a suspendu le chantier', 'detail' => 'Sécurité.', 'from' => null, 'to' => null, 'at' => '2026-11-04T10:00:00+00:00']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1]]),
            self::API.'/sites/'.self::ID => Http::response(['data' => $this->detail()]),
        ]);
        $page = '/chantiers/'.self::ID;

        $this->get("{$page}/equipe")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.kind', 'team')->where('relations.members.0.name', 'Jean Kouassi')->where('relations.today.present', 1)->where('relations.days.0.rate', 100)->where('tabCounts.incidents', 1));
        $this->get("{$page}/incidents")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.rows.0.severity.tone', 'o')->where('relations.types.0.label', 'Sécurité'));
        $this->get("{$page}/documents")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.rows.0.files.0.name', 'plan.pdf'));
        $this->get("{$page}/historique")->assertOk()->assertInertia(fn (Assert $p) => $p->where('relations.rows.0.action', 'a suspendu le chantier')->where('relations.rows.0.target', 'Sécurité.'));

        $this->from("{$page}/incidents")->post("{$page}/incidents", ['incident_type' => 'securite', 'severity' => 'grave', 'occurred_at' => '2026-11-04', 'description' => 'Chute.'])->assertSessionHas('inertia.flash_data.toast', 'Incident enregistré.');
        $this->from("{$page}/incidents")->post("{$page}/incidents/{$incident}/resolution", ['resolution' => 'Réparé.'])->assertSessionHas('inertia.flash_data.toast', 'Incident résolu.');
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['sites.read']);
        Http::fake([self::API.'/sites*' => Http::response($this->listResponse([]))]);

        $this->get('/chantiers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['update' => false, 'validate' => false, 'export' => false]));
    }
}
