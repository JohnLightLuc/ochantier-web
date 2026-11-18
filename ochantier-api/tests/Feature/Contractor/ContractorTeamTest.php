<?php

namespace Tests\Feature\Contractor;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Testing\TestResponse;

class ContractorTeamTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->subscribe();
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->site = $this->offer->site_id;

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => $this->day(10), 'end_date' => $this->day(39), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-equipe'])->assertCreated();
        $this->asContractor();
    }

    public function test_the_catalog_lists_validated_workers_with_their_net_rate(): void
    {
        $mason = $this->worker('Yao Koffi', 'macon', 20000);
        $this->worker('Awa Traoré', 'plombier', 18000);
        $this->worker('Ali Ouattara', 'macon', 15000, AvailabilityStatus::Indisponible);
        $this->worker('Refusé Dossier', 'macon', 9000, status: WorkerVerificationStatus::Refuse);

        $this->getJson('/api/v1/contractor/workers?trade_id='.$this->trade('macon'))
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.name', 'Yao Koffi')
            ->assertJsonPath('data.0.daily_rate', 20000)
            ->assertJsonPath('data.0.tag', 'Disponible')
            ->assertJsonPath('data.1.selectable', false);
        $this->getJson('/api/v1/contractor/workers?available=1&max_rate=19000')->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.name', 'Awa Traoré');

        $this->assign([$mason])->assertCreated();

        $this->getJson("/api/v1/contractor/workers?site_id={$this->site}&search=yao")
            ->assertJsonPath('data.0.in_team', true)
            ->assertJsonPath('data.0.tag', 'Dans l’équipe');
        $this->getJson("/api/v1/contractor/workers/{$mason->id}")
            ->assertOk()
            ->assertJsonPath('data.busy_periods.0.start_date', $this->day(10));
    }

    public function test_direct_assignments_wait_for_the_worker_agreement(): void
    {
        $mason = $this->worker('Yao Koffi', 'macon', 20000);
        $plumber = $this->worker('Awa Traoré', 'plombier', 18000);

        $this->assign([$mason], start: $this->day(5))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_date.0', 'La période doit être comprise dans les dates du chantier ('.now()->addDays(10)->format('d/m/Y').' – '.now()->addDays(39)->format('d/m/Y').').');

        $this->assign([$mason, $plumber])
            ->assertCreated()
            ->assertJsonPath('message', '2 ouvriers ajoutés à l’équipe (accords en attente).')
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.pending_agreements', 2)
            ->assertJsonPath('data.daily_cost', 38000)
            ->assertJsonPath('data.planned_cost', 38000 * 20)
            ->assertJsonPath('data.members.0.display_status', ['value' => 'en_attente', 'label' => 'En attente d’accord']);

        $this->assertSame('Yao Koffi est déjà affecté du '.now()->addDays(10)->format('d/m/Y').' au '.now()->addDays(29)->format('d/m/Y').' (Résidence Les Acacias).', $this->assign([$mason], start: $this->day(25), end: $this->day(30))->assertUnprocessable()->json('errors')['workers.0.worker_id'][0]);

        $mission = Mission::query()->whereNotNull('contractor_id')->firstOrFail();
        $this->assertSame(0, $mission->pricingSnapshot->total_promoter_amount);
        $this->assertSame(0, $mission->paymentSchedules()->count());

        $admin = $this->asAdmin();
        $pending = $this->getJson('/api/v1/admin/assignments/pending-agreements')->assertOk()->assertJsonPath('meta.count', 2)->json('data');
        $this->assertSame('Yao Serge Koffi', $pending[0]['contractor']['name']);

        $masonAssignment = Assignment::query()->where('worker_id', $mason->id)->sole();
        $this->postJson("/api/v1/admin/assignments/{$masonAssignment->id}/agreement", ['decision' => 'accepter'])
            ->assertOk()
            ->assertJsonPath('message', 'Accord de l’ouvrier enregistré.');
        $this->postJson("/api/v1/admin/assignments/{$masonAssignment->id}/agreement", ['decision' => 'accepter'])->assertUnprocessable();
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'team.accepted')->exists());

        $this->artisan('ochantier:simulate-worker-agreement', ['decision' => 'refuser'])->assertSuccessful();
        $this->assertSame(AssignmentStatus::Refusee, Assignment::query()->where('worker_id', $plumber->id)->sole()->status);

        $this->asContractor()->getJson("/api/v1/contractor/sites/{$this->site}/team")
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.members.0.display_status.value', 'confirmee')
            ->assertJsonPath('data.history.0.status.value', 'refusee');
        $this->getJson("/api/v1/contractor/sites/{$this->site}")->assertJsonPath('data.team.count', 1);
        $this->assertNotNull($admin);
    }

    public function test_a_confirmed_worker_is_replaced_from_the_effective_date(): void
    {
        $mason = $this->worker('Yao Koffi', 'macon', 20000);
        $other = $this->worker('Bamba Seydou', 'macon', 22000);
        $this->assign([$mason])->assertCreated();
        $assignment = Assignment::query()->sole();

        $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}/replace", ['reason' => 'Indisponible', 'effective_date' => $this->day(15), 'worker_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.assignment.0', 'L’ouvrier n’a pas encore accepté : annulez plutôt l’affectation.');

        $this->artisan('ochantier:simulate-worker-agreement', ['decision' => 'accepter', 'assignment' => $assignment->id])->assertSuccessful();
        $this->travel(12)->days();

        $this->getJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}")
            ->assertJsonPath('data.actions', ['replace' => true, 'cancel' => false]);
        $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}/replace", ['reason' => 'Autre', 'effective_date' => now()->addDays(2)->toDateString(), 'worker_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.other_reason.0', 'Précisez le motif du remplacement.');

        $effective = now()->addDays(2)->toDateString();
        $replacement = $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}/replace", ['reason' => 'Absences répétées', 'effective_date' => $effective, 'worker_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('message', 'Remplacement enregistré. Accord du nouvel ouvrier requis.')
            ->assertJsonPath('data.worker.name', 'Bamba Seydou')
            ->assertJsonPath('data.start_date', $effective)
            ->assertJsonPath('data.daily_rate', 22000)
            ->assertJsonPath('data.replaces', 'Yao Koffi')
            ->json('data.id');

        $this->getJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}")
            ->assertJsonPath('data.status.value', 'remplacee')
            ->assertJsonPath('data.end_date', now()->addDay()->toDateString())
            ->assertJsonPath('data.replacement', ['reason' => 'Absences répétées', 'effective_date' => $effective, 'replaced_by' => 'Bamba Seydou']);
        $this->assertSame($assignment->mission_id, Assignment::query()->findOrFail($replacement)->mission_id);
    }

    public function test_an_assignment_can_be_cancelled_before_it_starts(): void
    {
        $this->assign([$this->worker('Yao Koffi', 'macon', 20000)])->assertCreated();
        $assignment = Assignment::query()->sole();

        $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.history.0.status.value', 'annulee');
        $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments/{$assignment->id}/cancel")->assertUnprocessable();
        $this->assertSame('annulee', $assignment->mission->refresh()->status->value);
    }

    public function test_the_promoter_sees_the_contractor_team_without_managing_it(): void
    {
        $mason = $this->worker('Yao Koffi', 'macon', 20000);
        $this->assign([$mason])->assertCreated();
        $this->artisan('ochantier:simulate-worker-agreement', ['decision' => 'accepter'])->assertSuccessful();

        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$this->site}/team")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Yao Koffi']);
        $this->postJson("/api/v1/promoter/sites/{$this->site}/reservations", ['needs' => [['trade_id' => $this->trade('macon'), 'headcount' => 1, 'daily_rate' => 20000]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.site.0', 'Ce chantier est confié à un entrepreneur : c’est lui qui constitue et gère l’équipe.');
    }

    /**
     * @param  list<Worker>  $workers
     */
    private function assign(array $workers, ?string $start = null, ?string $end = null): TestResponse
    {
        return $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments", [
            'start_date' => $start ?? $this->day(10),
            'end_date' => $end ?? $this->day(29),
            'workers' => array_map(fn (Worker $worker) => ['worker_id' => $worker->id, 'role' => 'Chef d’équipe', 'daily_rate' => (int) $worker->estimated_daily_rate], $workers),
        ]);
    }

    private function day(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }

    private function asAdmin(): Admin
    {
        $admin = Admin::factory()->role('operations')->create();
        $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);

        return $admin;
    }
}
