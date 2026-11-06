<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Pointages branchées sur l’API (api/v1/admin/attendance).
 */
class AttendancesTest extends TestCase
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
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['attendance.read', 'attendance.update', 'attendance.export'], 'must_change_password' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return ['id' => self::ID, 'date' => '2026-11-04', 'status' => ['value' => 'a_valider', 'label' => 'À valider'], 'worker' => ['id' => 'w1', 'reference' => 'OUV-1', 'full_name' => 'Jean Kouassi'], 'site' => ['id' => 's1', 'reference' => 'CHT-1', 'label' => 'Résidence Les Palmiers', 'commune' => 'Cocody'], 'promoter' => ['name' => 'Bâtir Afrique'], 'trade' => 'Maçon', 'arrival' => '08:00', 'departure' => '17:00', 'worked_minutes' => 480, 'out_of_zone' => true, 'open_anomalies' => 1, 'is_locked' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function anomaly(): array
    {
        return ['id' => 'x1', 'type' => ['value' => 'hors_zone', 'label' => 'Hors zone'], 'status' => ['value' => 'ouverte', 'label' => 'Ouverte'], 'detected_by' => ['value' => 'systeme', 'label' => 'Système'], 'comment' => 'Pointage à 400 m', 'resolution' => null, 'resolved_by' => null, 'occurred_at' => null, 'resolved_at' => null, 'work_day_id' => self::ID, 'date' => '2026-11-04', 'worker' => ['id' => 'w1', 'reference' => 'OUV-1', 'full_name' => 'Jean Kouassi'], 'site' => ['id' => 's1', 'label' => 'Résidence Les Palmiers'], 'is_open' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function correction(): array
    {
        return ['id' => 'c1', 'status' => ['value' => 'demandee', 'label' => 'Demandée'], 'reason' => 'Parti le matin.', 'old_values' => ['status' => 'valide', 'worked_minutes' => 480], 'new_values' => ['worked_minutes' => 300, 'status' => 'rejete'], 'requested_by' => 'Awa Koné', 'decided_by' => null, 'created_at' => null, 'decided_at' => null, 'work_day_id' => self::ID, 'date' => '2026-11-04', 'worker' => ['id' => 'w1', 'reference' => 'OUV-1', 'full_name' => 'Jean Kouassi'], 'site' => ['id' => 's1', 'label' => 'Résidence Les Palmiers'], 'is_pending' => true, 'can_approve' => true];
    }

    public function test_the_list_translates_the_filters(): void
    {
        Http::fake([self::API.'/attendance*' => Http::response(['data' => [$this->row()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['total' => 1, 'statuses' => ['en_cours' => 0, 'a_valider' => 1, 'valide' => 0, 'rejete' => 0, 'absent' => 0, 'annule' => 0], 'todo' => ['retard' => 1, 'incomplets' => 0, 'absences' => 0, 'hors_zone' => 1, 'anomalies' => 1]], 'trades' => [['id' => 't1', 'label' => 'Maçon']], 'communes' => []]])]);

        $this->get('/pointages?q=jean&statut=a_valider&rapide=retard&periode=aujourdhui&tri=duree&sens=desc')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attendances/Index')->where('attendances.data.0.worker.full_name', 'Jean Kouassi')->where('attendances.data.0.status.tone', 'a')->where('kpis.pending', 1)->where('todo.hors_zone', 1));
        $today = today()->toDateString();
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=jean') && str_contains($r->url(), 'todo=retard') && str_contains($r->url(), 'sort=minutes') && str_contains($r->url(), "date_from={$today}") && str_contains($r->url(), "date_to={$today}"));
    }

    public function test_the_fiche_and_the_reminder_reach_the_api(): void
    {
        Http::fake([
            self::API.'/attendance/'.self::ID.'/remind' => Http::sequence()->push(['message' => 'Promoteur relancé.'])->push(['message' => 'Refus.', 'errors' => ['attendance' => ['Le promoteur a déjà été relancé aujourd’hui pour cette journée.']]], 422),
            self::API.'/attendance/'.self::ID => Http::response(['data' => [...$this->row(), 'assignment_id' => 'a1', 'observation' => null, 'validated_at' => null, 'validated_by' => null, 'events' => ['arrival' => ['time' => '08:00', 'distance_m' => 12, 'accuracy_m' => 8, 'out_of_zone' => false, 'author' => 'Awa Yao', 'photo_url' => 'http://x/p.jpg', 'signature_url' => 'http://x/s.png', 'status' => ['label' => 'Soumis']], 'departure' => null], 'anomalies' => [$this->anomaly()], 'corrections' => [$this->correction()], 'can' => ['remind' => true, 'correct' => ['minutes' => true, 'status' => 'rejete']]]]),
        ]);
        $page = '/pointages/'.self::ID;

        $this->get($page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Attendances/Show')->where('attendance.arrivalProof.distance', 12)->where('attendance.departureProof', null)->where('attendance.anomalies.0.type', 'Hors zone')->where('attendance.anomalies.0.status.tone', 'r')->where('reportTypes.0.value', 'retard')->where('attendance.canRemind', true)->where('attendance.correct.status', 'rejete')->where('attendance.corrections.0.changes', 'Durée : 8 h 00 → 5 h 00 · Statut : Validé → Rejeté'));

        $this->from($page)->post("{$page}/relance")->assertRedirect($page)->assertSessionHas('inertia.flash_data.toast', 'Promoteur relancé.');
        $this->from($page)->post("{$page}/relance")->assertSessionHas('inertia.flash_data.toastError', 'Le promoteur a déjà été relancé aujourd’hui pour cette journée.');
    }

    public function test_the_anomalies_page_and_actions_reach_the_api(): void
    {
        Http::fake([
            self::API.'/attendance/anomalies/'.self::ID.'/start' => Http::response(['message' => 'Anomalie prise en charge.']),
            self::API.'/attendance/anomalies/'.self::ID.'/close' => Http::response(['message' => 'Refus.', 'errors' => ['anomaly' => ['Cette anomalie est déjà clôturée.']]], 422),
            self::API.'/attendance/'.self::ID.'/anomalies' => Http::response(['message' => 'Anomalie signalée.']),
            self::API.'/attendance/anomalies*' => Http::response(['data' => [$this->anomaly()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['ouverte' => 1, 'en_traitement' => 0, 'resolue' => 0, 'rejetee' => 0], 'types' => [['value' => 'hors_zone', 'label' => 'Hors zone']]]]),
        ]);
        $page = '/pointages/anomalies';

        $this->get($page.'?q=jean&statut=ouverte&type=hors_zone')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Attendances/Anomalies')->where('anomalies.data.0.worker.full_name', 'Jean Kouassi')->where('kpis.open', 1)->where('filterOptions.type.0.value', 'hors_zone'));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=jean') && str_contains($r->url(), 'status=ouverte') && str_contains($r->url(), 'type=hors_zone'));

        $this->from($page)->post('/pointages/'.self::ID.'/anomalies', ['type' => 'doublon', 'comment' => ' Deux pointages. '])->assertSessionHas('inertia.flash_data.toast', 'Anomalie signalée.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/anomalies') && $r['comment'] === 'Deux pointages.');
        $this->from($page)->post('/pointages/anomalies/'.self::ID.'/prise-en-charge')->assertSessionHas('inertia.flash_data.toast', 'Anomalie prise en charge.');
        $this->from($page)->post('/pointages/anomalies/'.self::ID.'/cloture', ['outcome' => 'resolue', 'resolution' => 'Vu avec le promoteur.'])->assertSessionHas('inertia.flash_data.toastError', 'Cette anomalie est déjà clôturée.');
    }

    public function test_the_corrections_page_and_actions_reach_the_api(): void
    {
        Http::fake([
            self::API.'/attendance/corrections/'.self::ID.'/approve' => Http::response(['message' => 'Refus.', 'errors' => ['attendance' => ['Une autre personne doit approuver cette correction.']]], 422),
            self::API.'/attendance/corrections/'.self::ID.'/refuse' => Http::response(['message' => 'Correction refusée.']),
            self::API.'/attendance/'.self::ID.'/corrections' => Http::response(['message' => 'Correction demandée : une autre personne doit l’approuver.']),
            self::API.'/attendance/corrections*' => Http::response(['data' => [$this->correction()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['demandee' => 1, 'approuvee' => 0, 'refusee' => 0], 'status_labels' => []]]),
        ]);
        $page = '/pointages/corrections';

        $this->get($page.'?q=jean&statut=demandee')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Attendances/Corrections')->where('corrections.data.0.canApprove', true)->where('kpis.pending', 1));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=jean') && str_contains($r->url(), 'status=demandee'));

        $this->from($page)->post('/pointages/'.self::ID.'/corrections', ['hours' => '7,5', 'status' => '', 'reason' => ' Parti plus tôt ce jour. '])->assertSessionHas('inertia.flash_data.toast');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/corrections') && $r->method() === 'POST' && $r['worked_minutes'] === 450 && $r['status'] === null);
        $this->from($page)->post('/pointages/corrections/'.self::ID.'/approbation')->assertSessionHas('inertia.flash_data.toastError', 'Une autre personne doit approuver cette correction.');
        $this->from($page)->post('/pointages/corrections/'.self::ID.'/refus')->assertSessionHas('inertia.flash_data.toast', 'Correction refusée.');
    }

    public function test_old_addresses_lead_to_the_list(): void
    {
        $this->get('/pointages/PT-2026-0001')->assertRedirect('/pointages');
        $this->get('/pointages/aujourdhui')->assertRedirect('/pointages');
    }
}
