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
            'access_link' => ['state' => ['value' => 'aucun', 'label' => 'Aucun lien envoyé'], 'sent_at' => null, 'expires_at' => null, 'used_at' => null],
            'password_set' => true,
            'zone_request_pending' => true,
            'documents' => ['slots' => [['key' => 'commercial_cni', 'label' => 'Pièce d’identité du commercial', 'required' => true, 'state' => ['value' => 'manquant', 'label' => 'Manquant'], 'document' => null]], 'others' => []],
            'can' => ['suspend' => true, 'reactivate' => false, 'terminate' => true, 'edit' => true, 'send_link' => true],
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
            'can' => ['suspend' => false, 'reactivate' => true, 'terminate' => true, 'edit' => true, 'send_link' => false],
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

        $this->get('/commerciaux/'.self::ID.'/profil-documents')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', 'profil-documents')->where('agent.address', 'Angré')->where('documents.0.status.label', 'Manquant'));
    }

    public function test_old_links_lead_to_the_search_or_the_list(): void
    {
        $this->get('/commerciaux/koffi-yao/gains-paiements')->assertRedirect('/commerciaux?q=Koffi%20Yao');
        $this->get('/commerciaux/classement')->assertRedirect('/commerciaux');
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

    public function test_the_access_tab_reads_the_sessions_and_link_actions_reach_the_api(): void
    {
        Http::fake([
            self::API.'/commercials/'.self::ID.'/access' => Http::response(['data' => ['password_set' => true, 'must_change_password' => false, 'phone_verified_at' => null, 'last_login_at' => null, 'failed_login_count' => 1, 'locked_until' => null, 'sessions' => [['id' => 's1', 'device_name' => 'Samsung A54', 'platform' => 'android', 'app_version' => '1.0', 'current' => false, 'last_activity_at' => '2026-11-03T08:00:00+00:00', 'created_at' => '2026-11-03T08:00:00+00:00']]]]),
            self::API.'/commercials/'.self::ID.'/access-link' => Http::response(['message' => 'Lien envoyé, valable 72 heures.']),
            self::API.'/commercials/'.self::ID => Http::response(['data' => $this->detail()]),
        ]);

        $this->get('/commerciaux/'.self::ID.'/acces-securite')->assertOk()->assertInertia(fn (Assert $page) => $page->where('access.sessions.0.device', 'Samsung A54')->where('access.failedLogins', 1));
        $this->from('/commerciaux/'.self::ID)->post('/commerciaux/'.self::ID.'/lien-acces')->assertSessionHas('inertia.flash_data.toast', 'Lien envoyé, valable 72 heures.');
    }

    public function test_the_form_sends_the_api_body_and_errors_come_back_on_the_fields(): void
    {
        Http::fake([
            self::API.'/commercials/form-options' => Http::response(['data' => ['supervisors' => [['id' => 'c9', 'full_name' => 'Yao Konan']], 'cities' => [['id' => 'v1', 'name' => 'Abidjan', 'communes' => [['id' => 'c1', 'name' => 'Cocody']]]], 'document_types' => []]]),
            self::API.'/commercials' => Http::sequence()
                ->push(['message' => 'Numéro déjà utilisé.', 'errors' => ['phone_number' => ['Numéro déjà utilisé.']]], 422)
                ->push(['message' => 'Commercial créé.', 'data' => ['id' => self::ID]], 201),
        ]);

        $this->get('/commerciaux/nouveau')->assertOk()->assertInertia(fn (Assert $page) => $page->component('SalesAgents/Form')->where('options.commune.0.label', 'Cocody · Abidjan')->where('values.send_link', true));

        $form = ['first_name' => 'Fatou', 'last_name' => 'Diallo', 'phone' => '+225 05 00 00 00 77', 'started_at' => '2026-11-02', 'primary_commune_id' => 'c1', 'secondary_commune_ids' => [], 'send_link' => true];
        $this->from('/commerciaux/nouveau')->post('/commerciaux', $form)->assertSessionHasErrors(['phone' => 'Numéro déjà utilisé.']);
        $this->post('/commerciaux', $form)->assertRedirect('/commerciaux/'.self::ID.'/acces-securite');

        Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST' && $request['phone_number'] === '0500000077' && $request['dial_code'] === '225' && $request['send_link'] === true);
    }

    public function test_the_activation_page_explains_an_invalid_link_and_saves_the_password(): void
    {
        $this->app['auth']->forgetGuards();
        Http::fake(fn (ClientRequest $request) => match (true) {
            str_contains($request->url(), 'token=perime') => Http::response(['message' => 'Lien expiré.', 'errors' => ['token' => ['Lien expiré.']]], 422),
            $request->method() === 'GET' => Http::response(['data' => ['first_name' => 'Fatou', 'phone' => '+225 05 •• •• 77', 'expires_at' => '2026-11-08T08:00:00+00:00']]),
            default => Http::response(['message' => 'Mot de passe enregistré.']),
        });

        $this->get('/activation-commercial?token=perime')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('step', 'activation')->where('firstName', null));
        $this->get('/activation-commercial?token=bon')->assertOk()->assertInertia(fn (Assert $page) => $page->where('firstName', 'Fatou'));
        $this->post('/activation-commercial', ['token' => 'bon', 'password' => 'motdepasse2', 'password_confirmation' => 'motdepasse2'])->assertRedirect('/activation-commercial');
    }

    public function test_the_field_tabs_read_only_their_own_data_and_decisions_reach_the_api(): void
    {
        Http::fake([
            self::API.'/commercials/form-options' => Http::response(['data' => ['supervisors' => [['id' => self::ID, 'full_name' => 'Adama Coulibaly'], ['id' => 'c9', 'full_name' => 'Yao Konan']], 'cities' => [['id' => 'v1', 'name' => 'Abidjan', 'communes' => [['id' => 'c1', 'name' => 'Cocody']]]], 'document_types' => []]]),
            self::API.'/commercials/'.self::ID.'/workers/transfer' => Http::response(['message' => '2 ouvriers transférés.']),
            self::API.'/commercials/'.self::ID.'/workers*' => Http::response(['data' => [['id' => 'w1', 'full_name' => 'Jean Kouassi', 'reference' => 'OUV-000001', 'trade' => ['label' => 'Maçon'], 'commune' => ['name' => 'Cocody'], 'status' => ['value' => 'valide', 'label' => 'Validé'], 'submitted_at' => '2026-11-02T10:00:00+00:00']], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['valide' => 1, 'soumis' => 0], 'transfers' => []]]),
            self::API.'/commercials/'.self::ID.'/prospects*' => Http::response(['data' => [['id' => 'p1', 'full_name' => 'Ali Touré', 'phone' => '+225 07', 'trade' => null, 'commune' => null, 'stage' => ['value' => 'pret', 'label' => 'Prêt à soumettre'], 'completion' => ['percent' => 100], 'hint' => 'Prêt à soumettre', 'created_at' => '2026-11-02T10:00:00+00:00', 'last_followup_at' => null]], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['nouveau' => 1]]]),
            self::API.'/commercials/'.self::ID.'/zone-requests/'.self::ID.'/accept' => Http::response(['message' => 'Demande acceptée.']),
            self::API.'/commercials/'.self::ID.'/zone-requests' => Http::response(['data' => ['requests' => [['id' => 'r1', 'communes' => [['id' => 'c1', 'name' => 'Cocody']], 'status' => ['value' => 'en_attente', 'label' => 'En attente'], 'created_at' => '2026-11-02T10:00:00+00:00', 'decision_comment' => null, 'decided_by' => null, 'decided_at' => null]]]]),
            self::API.'/commercials/'.self::ID.'/objectives' => Http::response(['data' => [['period' => ['start' => '2026-11-01', 'end' => '2026-11-30'], 'period_label' => 'Nov 2026', 'target' => 8, 'achieved' => 2, 'percent' => 25, 'comment' => null, 'can_edit' => true]], 'message' => 'Objectif enregistré.']),
            self::API.'/commercials/'.self::ID => Http::response(['data' => $this->detail()]),
        ]);

        $this->get('/commerciaux/'.self::ID.'/ouvriers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('relations.kind', 'workers')->where('relations.rows.0.code', 'OUV-000001')->where('relations.pagination.to', 1)->has('relations.filters', 2)->has('fieldOptions.colleagues', 1)->where('fieldOptions.colleagues.0.label', 'Yao Konan')->where('tabCounts.zones', 1));
        $this->get('/commerciaux/'.self::ID.'/activite?statut=nouveau')->assertOk()->assertInertia(fn (Assert $page) => $page->where('relations.kind', 'prospects')->where('relations.status', 'nouveau')->where('relations.rows.0.status.tone', 'g')->where('fieldOptions', null));
        $this->get('/commerciaux/'.self::ID.'/zones')->assertOk()->assertInertia(fn (Assert $page) => $page->where('relations.requests.0.status.tone', 'a')->where('agent.zoneList.0.primary', true));
        $this->get('/commerciaux/'.self::ID.'/objectifs')->assertOk()->assertInertia(fn (Assert $page) => $page->where('relations.rows.0.month', '2026-11'));

        $this->from('/commerciaux/'.self::ID.'/ouvriers')->post('/commerciaux/'.self::ID.'/ouvriers/transfert', ['to_commercial_id' => 'c9', 'worker_ids' => ['w1'], 'reason' => 'Changement de secteur.'])->assertSessionHas('inertia.flash_data.toast', '2 ouvriers transférés.');
        Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/workers/transfer') && $request['worker_ids'] === ['w1']);
        $this->from('/commerciaux/'.self::ID.'/zones')->post('/commerciaux/'.self::ID.'/demandes-zone/'.self::ID.'/acceptation', ['primary_commune_id' => 'c1'])->assertSessionHas('inertia.flash_data.toast', 'Demande acceptée.');
        $this->from('/commerciaux/'.self::ID.'/objectifs')->post('/commerciaux/'.self::ID.'/objectifs', ['month' => '2026-11', 'target' => '8'])->assertSessionHas('inertia.flash_data.toast', 'Objectif enregistré.');
    }

    public function test_the_buttons_follow_the_admin_permissions(): void
    {
        $this->signIn(['commercials.read']);
        Http::fake([self::API.'/commercials*' => Http::response($this->listResponse([]))]);

        $this->get('/commerciaux')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'update' => false, 'terminate' => false, 'export' => false, 'documents' => false, 'validate' => false]));
    }
}
