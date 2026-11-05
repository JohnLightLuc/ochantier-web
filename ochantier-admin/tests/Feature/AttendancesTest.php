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
            self::API.'/attendance/'.self::ID => Http::response(['data' => [...$this->row(), 'assignment_id' => 'a1', 'observation' => null, 'validated_at' => null, 'validated_by' => null, 'events' => ['arrival' => ['time' => '08:00', 'distance_m' => 12, 'accuracy_m' => 8, 'out_of_zone' => false, 'author' => 'Awa Yao', 'photo_url' => 'http://x/p.jpg', 'signature_url' => 'http://x/s.png', 'status' => ['label' => 'Soumis']], 'departure' => null], 'anomalies' => [['id' => 'x1', 'type' => ['label' => 'Hors zone'], 'status' => ['label' => 'Ouverte'], 'detected_by' => ['label' => 'Système'], 'comment' => 'Pointage à 400 m', 'resolution' => null]], 'corrections' => [], 'can' => ['remind' => true]]]),
        ]);
        $page = '/pointages/'.self::ID;

        $this->get($page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Attendances/Show')->where('attendance.arrivalProof.distance', 12)->where('attendance.departureProof', null)->where('attendance.anomalies.0.type', 'Hors zone')->where('attendance.canRemind', true));

        $this->from($page)->post("{$page}/relance")->assertRedirect($page)->assertSessionHas('inertia.flash_data.toast', 'Promoteur relancé.');
        $this->from($page)->post("{$page}/relance")->assertSessionHas('inertia.flash_data.toastError', 'Le promoteur a déjà été relancé aujourd’hui pour cette journée.');
    }

    public function test_old_addresses_lead_to_the_list(): void
    {
        $this->get('/pointages/PT-2026-0001')->assertRedirect('/pointages');
        $this->get('/pointages/aujourdhui')->assertRedirect('/pointages');
    }
}
