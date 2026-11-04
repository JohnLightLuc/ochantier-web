<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Commerciaux branchées sur l’API (api/v1/admin/commercials) : liste, export, fiche et décisions sur le compte.
 */
class SalesAgentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000c1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        $this->signIn(['commercials.read', 'commercials.update', 'commercials.delete', 'commercials.export']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function signIn(array $permissions): void
    {
        app(ApiTokens::class)->store([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 900,
            'session_id' => '9a0e0000-0000-4000-8000-000000000001',
            'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => $permissions, 'must_change_password' => false],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'id' => self::ID, 'employee_number' => 'COM-00001', 'first_name' => 'Adama', 'last_name' => 'Coulibaly', 'full_name' => 'Adama Coulibaly',
            'phone' => '+225 05 00 00 00 01', 'email' => 'adama@ochantier.ci', 'status' => ['value' => 'actif', 'label' => 'Actif'],
            'zones' => [['id' => 'c1', 'name' => 'Cocody', 'is_primary' => true], ['id' => 'c2', 'name' => 'Yopougon', 'is_primary' => false]],
            'supervisor' => ['id' => 'c9', 'full_name' => 'Yao Konan'],
            'workers' => ['total' => 12, 'month_registered' => 3, 'month_validated' => 2],
            'validation_rate' => 67, 'objective' => ['target' => 8, 'achieved' => 2, 'percent' => 25],
            'commissions' => ['month' => 10000, 'unpaid' => 15000], 'started_at' => '2026-01-05', 'last_activity_at' => '2026-11-02T10:00:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        return [
            ...$this->row(),
            'secondary_phone' => null, 'address' => 'Angré', 'is_supervisor' => false, 'verification_status' => ['value' => 'valide', 'label' => 'Validé'],
            'ended_at' => null, 'created_by' => 'Awa Koné', 'last_login_at' => '2026-11-03T08:00:00+00:00', 'suspension' => null, 'termination_reason' => null,
            'ranking' => ['rank' => 2, 'total' => 5, 'label' => '2ᵉ sur 5 commerciaux'],
            'months' => [['month' => '2026-11', 'label' => 'Nov', 'registered' => 3, 'validated' => 2]],
            'trades' => [['label' => 'Maçon', 'count' => 4]],
            'can' => ['suspend' => true, 'reactivate' => false, 'terminate' => true],
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
            'counts' => ['total' => 3, 'statuses' => ['en_attente_activation' => 1, 'actif' => 2, 'suspendu' => 0, 'termine' => 0], 'todo' => ['objectifs' => 1, 'relances' => 2, 'gains' => 1], 'workers' => 12, 'unpaid' => 15000],
            'communes' => [['id' => 'c1', 'name' => 'Cocody']],
            'supervisors' => [['id' => 'c9', 'full_name' => 'Yao Konan']],
        ]];
    }

    public function test_the_list_translates_the_filters_and_shows_the_api_rows(): void
    {
        Http::fake([self::API.'/commercials*' => Http::response($this->listResponse([$this->row()]))]);

        $this->get('/commerciaux?q=adama&statut=actif&zone=c1&superviseur=c9&periode=mois&rapide=gains&tri=commissions&sens=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SalesAgents/Index')
                ->where('agents.total', 1)
                ->where('agents.data.0.name', 'Adama Coulibaly')
                ->where('agents.data.0.zone', 'Cocody')
                ->where('agents.data.0.secondaryZones', ['Yopougon'])
                ->where('agents.data.0.status.tone', 'g')
                ->where('agents.data.0.lastActivityOn', '2026-11-02')
                ->where('kpis', ['total' => 3, 'active' => 2, 'recruited' => 12, 'payable' => 15000])
                ->where('todo.followUp', 2)
                ->where('filterOptions.zone.0.label', 'Cocody')
                ->where('can.terminate', true));

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'search=adama')
            && str_contains($request->url(), 'status=actif')
            && str_contains($request->url(), 'commune_id=c1')
            && str_contains($request->url(), 'supervisor_id=c9')
            && str_contains($request->url(), 'period=mois')
            && str_contains($request->url(), 'todo=gains')
            && str_contains($request->url(), 'sort=commissions')
            && str_contains($request->url(), 'direction=desc'));
    }

    public function test_the_export_relays_the_api_csv(): void
    {
        Http::fake([self::API.'/commercials/export*' => Http::response("Matricule;Nom\n", 200, ['Content-Disposition' => 'attachment; filename="commerciaux-2026-11-04.csv"'])]);

        $this->get('/commerciaux/export?ids='.self::ID.',pas-un-uuid')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="commerciaux-2026-11-04.csv"');

        Http::assertSent(fn (ClientRequest $request) => str_contains(urldecode($request->url()), 'ids[0]='.self::ID) && ! str_contains($request->url(), 'pas-un-uuid'));
    }

    public function test_the_fiche_shows_the_account_and_a_suspended_banner(): void
    {
        Http::fake([self::API.'/commercials/'.self::ID => Http::response(['data' => $this->detail([
            'status' => ['value' => 'suspendu', 'label' => 'Suspendu'],
            'suspension' => ['reason' => 'Profils dupliqués.', 'at' => '2026-11-03T09:00:00+00:00', 'by' => 'Awa Koné'],
            'can' => ['suspend' => false, 'reactivate' => true, 'terminate' => true],
        ])])]);

        $this->get('/commerciaux/'.self::ID)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('SalesAgents/Show')
                ->where('agent.code', 'COM-00001')
                ->where('agent.ranking.rank', 2)
                ->where('agent.can.reactivate', true)
                ->where('kpis.2.value', '2 / 8')
                ->where('banner.title', 'Compte suspendu.')
                ->where('banner.text', 'Motif : Profils dupliqués. Par Awa Koné.'));

        $this->get('/commerciaux/'.self::ID.'/profil')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', 'profil')->where('agent.address', 'Angré'));
    }

    public function test_old_links_lead_to_the_search_or_the_list(): void
    {
        $this->get('/commerciaux/koffi-yao/gains-paiements')->assertRedirect('/commerciaux?q=Koffi%20Yao');
        $this->get('/commerciaux/classement')->assertRedirect('/commerciaux');
        $this->get('/commerciaux/nouveau')->assertRedirect('/commerciaux');
        $this->get('/commerciaux/'.self::ID.'/gains-paiements')->assertRedirect('/commerciaux/'.self::ID);
    }

    public function test_decisions_are_sent_to_the_api_and_a_refusal_becomes_an_error_notification(): void
    {
        Http::fake([
            self::API.'/commercials/'.self::ID.'/suspension' => Http::response(['message' => 'Compte suspendu.']),
            self::API.'/commercials/'.self::ID.'/reactivation' => Http::response(['message' => 'Ce compte n’est ni suspendu ni terminé.', 'errors' => ['commercial' => ['Ce compte n’est ni suspendu ni terminé.']]], 422),
            self::API.'/commercials/'.self::ID.'/termination' => Http::response(['message' => 'Le motif est trop court.', 'errors' => ['reason' => ['Le motif est trop court.']]], 422),
        ]);

        $this->from('/commerciaux/'.self::ID)->post('/commerciaux/'.self::ID.'/suspension', ['category' => 'Inactivité prolongée', 'reason' => 'Aucun dossier depuis août.'])
            ->assertRedirect('/commerciaux/'.self::ID)
            ->assertSessionHas('inertia.flash_data.toast', 'Compte suspendu.');
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/suspension') && $request['reason'] === 'Inactivité prolongée · Aucun dossier depuis août.');

        $this->from('/commerciaux/'.self::ID)->post('/commerciaux/'.self::ID.'/reactivation')
            ->assertRedirect('/commerciaux/'.self::ID)
            ->assertSessionHas('inertia.flash_data.toastError', 'Ce compte n’est ni suspendu ni terminé.');

        $this->from('/commerciaux/'.self::ID)->post('/commerciaux/'.self::ID.'/fin-de-contrat', ['category' => 'Autre', 'reason' => 'x'])
            ->assertSessionHasErrors(['reason']);
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['commercials.read']);
        Http::fake([self::API.'/commercials*' => Http::response($this->listResponse([]))]);

        $this->get('/commerciaux')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['update' => false, 'terminate' => false, 'export' => false]));
    }
}
