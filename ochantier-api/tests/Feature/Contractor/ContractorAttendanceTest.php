<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Enums\WorkDayStatus;
use App\Models\AttendanceCorrection;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\WorkDay;
use App\Models\Worker;
use App\Support\Promoter\PromoterNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class ContractorAttendanceTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $site;

    private Worker $mason;

    private Worker $plumber;

    private CarbonImmutable $workDay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->subscribe();
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->site = $this->offer->site_id;
        $this->workDay = CarbonImmutable::today()->addDays(12);

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => $this->day(10), 'end_date' => $this->day(39), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-pointage'])->assertCreated();

        $this->mason = $this->worker('Yao Koffi', 'macon', 20000);
        $this->plumber = $this->worker('Awa Traoré', 'plombier', 18000);
        $pending = $this->worker('Ali Ouattara', 'macon', 15000);
        $this->asContractor()->postJson("/api/v1/contractor/sites/{$this->site}/assignments", [
            'start_date' => $this->day(10), 'end_date' => $this->day(29),
            'workers' => [
                ['worker_id' => $this->mason->id, 'role' => 'Maçon', 'daily_rate' => 20000],
                ['worker_id' => $this->plumber->id, 'role' => 'Plombier', 'daily_rate' => 18000],
            ],
        ])->assertCreated();
        $this->artisan('ochantier:simulate-worker-agreement', ['decision' => 'accepter'])->assertSuccessful();
        $this->postJson("/api/v1/contractor/sites/{$this->site}/assignments", [
            'start_date' => $this->day(10), 'end_date' => $this->day(29),
            'workers' => [['worker_id' => $pending->id, 'role' => 'Maçon', 'daily_rate' => 15000]],
        ])->assertCreated();

        $this->travelTo($this->workDay->setTime(8, 5));
        $this->asContractor();
    }

    public function test_the_contractor_points_the_team_and_validates_the_day(): void
    {
        $date = $this->workDay->toDateString();

        $sheet = $this->getJson("/api/v1/contractor/sites/{$this->site}/attendance")
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.stats.total', 2)
            ->json('data');
        $this->assertNotContains('Ali Ouattara', array_column($sheet['rows'], 'name'));
        $masonRow = collect($sheet['rows'])->firstWhere('name', 'Yao Koffi');
        $this->assertSame(20000, $masonRow['daily_rate']);

        $this->point($masonRow['assignment_id'], 'arrivee')
            ->assertCreated()
            ->assertJsonPath('message', 'Arrivée validée à 08:05');
        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/absences", ['assignment_id' => collect($sheet['rows'])->firstWhere('name', 'Awa Traoré')['assignment_id'], 'date' => $date])
            ->assertOk()
            ->assertJsonPath('message', 'Awa Traoré est marqué absent.');

        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/{$date}/validate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Pointez le départ ou l’absence de chaque ouvrier : 1 pointage à compléter.');

        $this->travelTo($this->workDay->setTime(17, 0));
        $this->point($masonRow['assignment_id'], 'depart')->assertCreated();

        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/{$date}/validate")
            ->assertOk()
            ->assertJsonPath('data.validated', true)
            ->assertJsonPath('data.stats', ['present' => 1, 'total' => 2, 'absent' => 1, 'pending' => 0])
            ->assertJsonPath('data.amount', 20000);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'attendance.validated')->exists());

        $this->getJson("/api/v1/contractor/sites/{$this->site}/attendance/history")
            ->assertOk()
            ->assertJsonPath('data.0.date', $date)
            ->assertJsonPath('data.0.presence_rate', 50);

        $this->asPromoter()->getJson("/api/v1/promoter/sites/{$this->site}/attendance?date={$date}")->assertOk()->assertJsonPath('data.validated', true);
        $this->postJson("/api/v1/promoter/sites/{$this->site}/attendance/absences", ['assignment_id' => $masonRow['assignment_id'], 'date' => $date])
            ->assertUnprocessable()
            ->assertJsonPath('errors.site.0', 'Le pointage de ce chantier est tenu par l’entrepreneur : vous pouvez le consulter.');
    }

    public function test_a_correction_keeps_the_previous_value(): void
    {
        $assignment = collect($this->getJson("/api/v1/contractor/sites/{$this->site}/attendance")->json('data.rows'))->firstWhere('name', 'Yao Koffi')['assignment_id'];
        $this->point($assignment, 'arrivee')->assertCreated();
        $this->travelTo($this->workDay->setTime(17, 0));
        $this->point($assignment, 'depart')->assertCreated();
        $day = WorkDay::query()->where('assignment_id', $assignment)->sole();

        $this->getJson("/api/v1/contractor/sites/{$this->site}/attendance/days/{$day->id}")
            ->assertOk()
            ->assertJsonPath('data.arrival.time', '08:05')
            ->assertJsonPath('data.departure.time', '17:00')
            ->assertJsonPath('data.can_correct', true);

        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/days/{$day->id}/correction", ['departure_time' => '18:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Le motif de la correction est obligatoire.');
        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/days/{$day->id}/correction", ['reason' => 'Oubli', 'departure_time' => '07:30'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.departure_time.0', 'Le départ doit suivre l’arrivée.');

        $before = $day->refresh()->worked_minutes;
        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/days/{$day->id}/correction", ['reason' => 'Départ pointé en avance', 'departure_time' => '18:00', 'observation' => 'Heures supplémentaires'])
            ->assertOk()
            ->assertJsonPath('message', 'Pointage corrigé. La valeur précédente est conservée dans l’historique.')
            ->assertJsonPath('data.departure.time', '18:00')
            ->assertJsonPath('data.observation', 'Heures supplémentaires')
            ->assertJsonPath('data.corrections.0.reason', 'Départ pointé en avance')
            ->assertJsonPath('data.corrections.0.old_values.departure', '17:00');
        $this->assertGreaterThan($before, $day->refresh()->worked_minutes);

        $this->postJson("/api/v1/contractor/sites/{$this->site}/attendance/days/{$day->id}/correction", ['reason' => 'Ouvrier finalement absent', 'absent' => true])
            ->assertOk()
            ->assertJsonPath('data.state.value', 'absent')
            ->assertJsonPath('data.arrival', null);
        $this->assertSame(WorkDayStatus::Absent, $day->refresh()->status);
        $this->assertSame(2, AttendanceCorrection::query()->count());
    }

    public function test_the_reminder_goes_to_the_contractor(): void
    {
        $assignment = collect($this->getJson("/api/v1/contractor/sites/{$this->site}/attendance")->json('data.rows'))->firstWhere('name', 'Yao Koffi')['assignment_id'];
        $this->point($assignment, 'arrivee')->assertCreated();

        $this->travelTo($this->workDay->addDay()->setTime(9, 0));
        app(PromoterNotifier::class)->reminders(CarbonImmutable::today());

        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'attendance.pending')->exists());
        $this->assertFalse(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'attendance.pending')->exists());
    }

    private function point(string $assignment, string $type): TestResponse
    {
        return $this->post("/api/v1/contractor/sites/{$this->site}/attendance/events", [
            'assignment_id' => $assignment,
            'type' => $type,
            'client_uuid' => (string) Str::uuid(),
            'latitude' => 5.36,
            'longitude' => -3.98,
            'accuracy' => 10,
            'photo' => $this->image('pointage.jpg'),
            'signature' => $this->image('signature.png'),
        ], ['Accept' => 'application/json']);
    }

    private function day(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }
}
