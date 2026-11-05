<?php

namespace Tests\Feature;

use App\Support\Api\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pages Affectations branchées sur l’API (api/v1/admin/assignments).
 */
class AssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://localhost:8001/api/v1/admin';

    private const ID = '01a16ab6-0000-7000-8000-0000000000a9';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ochantier_api.url' => self::API]);
        $this->withSession([]);
        $this->startSession();
        app(ApiTokens::class)->store(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900, 'session_id' => '9a0e0000-0000-4000-8000-000000000001', 'admin' => ['id' => '1', 'full_name' => 'Awa Koné', 'email' => 'a.kone@ochantier.ci', 'roles' => [], 'permissions' => ['missions.read', 'missions.update', 'missions.export'], 'must_change_password' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return ['id' => self::ID, 'status' => ['value' => 'active', 'label' => 'Active'], 'worker' => ['id' => 'w1', 'reference' => 'OUV-1', 'full_name' => 'Jean Kouassi', 'phone' => '+225 07'], 'site' => ['id' => 's1', 'reference' => 'CHT-1', 'label' => 'Résidence Les Palmiers', 'commune' => 'Cocody'], 'promoter' => ['id' => 'p1', 'name' => 'Bâtir Afrique'], 'mission' => ['id' => 'm1', 'reference' => 'MIS-1'], 'offer_id' => 'o1', 'trade' => 'Maçon', 'role' => null, 'is_team_lead' => false, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'promoter_daily_rate' => 24000, 'worker_net_daily_rate' => 22000, 'replacement' => null];
    }

    public function test_the_list_translates_the_filters(): void
    {
        Http::fake([self::API.'/assignments*' => Http::response(['data' => [$this->row()], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1, 'counts' => ['total' => 1, 'statuses' => ['confirmee' => 0, 'active' => 1, 'terminee' => 0, 'remplacee' => 0, 'annulee' => 0], 'todo' => ['remplacements' => 0, 'demarrage' => 0, 'fin' => 1]], 'trades' => [['id' => 't1', 'label' => 'Maçon']], 'communes' => [], 'reasons' => []]])]);

        $this->get('/affectations?q=jean&statut=active&metier=t1&rapide=fin&tri=fin&sens=desc')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Assignments/Index')->where('assignments.data.0.worker.full_name', 'Jean Kouassi')->where('assignments.data.0.status.tone', 'g')->where('kpis.active', 1)->where('todo.fin', 1));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'search=jean') && str_contains($r->url(), 'status=active') && str_contains($r->url(), 'todo=fin') && str_contains($r->url(), 'sort=end'));
    }

    public function test_the_fiche_and_the_declaration_reach_the_api(): void
    {
        Http::fake([
            self::API.'/assignments/'.self::ID.'/unavailability' => Http::sequence()->push(['message' => 'Indisponibilité déclarée : le remplacement est ouvert.'])->push(['message' => 'Refus.', 'errors' => ['assignment' => ['Un remplacement de Jean Kouassi est déjà en cours.']]], 422),
            self::API.'/assignments/'.self::ID => Http::response(['data' => [...$this->row(), 'actual_start_at' => null, 'actual_end_at' => null, 'end_reason' => null, 'replaces' => null, 'days' => ['validated' => 3, 'pending' => 1, 'absent' => 0], 'unavailabilities' => [['id' => 'u1', 'status' => ['label' => 'Déclarée'], 'declared_by' => ['label' => 'Admin'], 'reason' => 'Indisponibilité', 'comment' => null, 'effective_at' => '2026-11-04T10:00:00+00:00', 'is_urgent' => false]], 'replacements' => [['id' => 'r1', 'status' => ['label' => 'Recherche'], 'replaced_by' => null, 'created_at' => '2026-11-04T10:00:00+00:00']], 'can' => ['declare_unavailability' => true]], 'meta' => ['reasons' => [['code' => 'indisponibilite', 'label' => 'Indisponibilité']]]]),
        ]);
        $page = '/affectations/'.self::ID;

        $this->get($page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Assignments/Show')->where('assignment.days.validated', 3)->where('assignment.unavailabilities.0.date', '2026-11-04')->where('reasons.0.value', 'indisponibilite'));

        $this->from($page)->post("{$page}/indisponibilite", ['reason' => 'indisponibilite', 'comment' => ' '])->assertSessionHas('inertia.flash_data.toast', 'Indisponibilité déclarée : le remplacement est ouvert.');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/unavailability') && $r['comment'] === null);
        $this->from($page)->post("{$page}/indisponibilite", ['reason' => 'indisponibilite'])->assertSessionHas('inertia.flash_data.toastError', 'Un remplacement de Jean Kouassi est déjà en cours.');
    }

    public function test_old_addresses_lead_to_the_list(): void
    {
        $this->get('/affectations/besoins/ancien')->assertRedirect('/affectations');
    }
}
