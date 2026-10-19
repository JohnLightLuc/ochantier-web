<?php

namespace Tests\Feature\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\AttendanceAnomalyType;
use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkDayStatus;
use App\Models\AttendanceAnomaly;
use App\Models\AttendanceEvent;
use App\Models\Mission;
use App\Models\User;
use App\Models\WorkDay;

class AttendanceTest extends PromoterTestCase
{
    use ConfirmedSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
    }

    public function test_attendance_opens_on_the_planned_start_date(): void
    {
        $opensOn = today()->addDays(5);

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/attendance")
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.opens_on', $opensOn->toDateString())
            ->assertJsonPath('data.closed_message', 'Le pointage ouvrira le '.$opensOn->locale('fr')->translatedFormat('j M Y').', au démarrage du chantier.')
            ->assertJsonCount(0, 'data.rows');

        $this->point($this->assignmentOf('Kouamé Koffi'))
            ->assertJsonValidationErrors(['assignment_id' => 'Le pointage ouvrira le '.$opensOn->locale('fr')->translatedFormat('j M Y').', au démarrage du chantier.']);
    }

    public function test_the_first_arrival_starts_the_mission_and_a_replayed_submission_is_not_duplicated(): void
    {
        $this->onWorkDay();
        $kouame = $this->assignmentOf('Kouamé Koffi');

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/attendance")
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.stats', ['present' => 0, 'total' => 3, 'absent' => 0, 'pending' => 3])
            ->assertJsonPath('data.rows.0.state.label', 'Non pointé')
            ->assertJsonPath('data.rows.0.actions', ['arrivee', 'absent']);

        $proof = $this->proof($kouame);
        $this->post("/api/v1/promoter/sites/{$this->site->id}/attendance/events", $proof, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message', 'Arrivée validée à 08:00')
            ->assertJsonPath('data.stats.present', 1)
            ->assertJsonPath('data.rows.0.state.label', 'Arrivé à 08:00')
            ->assertJsonPath('data.rows.0.arrival.out_of_zone', false)
            ->assertJsonPath('data.rows.0.actions', ['depart']);

        $this->post("/api/v1/promoter/sites/{$this->site->id}/attendance/events", [...$proof, 'photo' => $this->image(), 'signature' => $this->image('signature.png')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame(1, AttendanceEvent::query()->count());

        $event = AttendanceEvent::query()->sole();
        $this->assertLessThan(15, (float) $event->distance_to_site_m);
        $this->assertSame(['latitude' => 5.3722, 'longitude' => -3.9583], $event->location);

        $mission = $kouame->mission->refresh();
        $this->assertSame(MissionStatus::EnCours, $mission->status);
        $this->assertSame(today()->toDateString(), $mission->actual_start_date->toDateString());
        $this->assertSame(AssignmentStatus::Active, $kouame->refresh()->status);
        $this->assertSame(NeedStatus::EnCours, $mission->need->status);
        $this->assertSame(SiteStatus::EnCours, $this->site->refresh()->status);
        $this->assertSame(MissionStatus::Confirmee, Mission::query()->whereKeyNot($mission->id)->sole()->status);

        $this->point($kouame)->assertJsonValidationErrors(['type' => 'L’arrivée de Kouamé Koffi est déjà pointée.']);
    }

    public function test_proofs_are_required_and_the_position_must_be_precise(): void
    {
        $this->onWorkDay();
        $kouame = $this->assignmentOf('Kouamé Koffi');

        $this->post("/api/v1/promoter/sites/{$this->site->id}/attendance/events", ['assignment_id' => $kouame->id, 'type' => 'arrivee', 'client_uuid' => fake()->uuid()], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors([
                'photo' => 'La photo de l’ouvrier est obligatoire.',
                'signature' => 'La signature de l’ouvrier est obligatoire.',
                'latitude' => 'La localisation est obligatoire.',
            ]);

        $this->point($kouame, overrides: ['accuracy' => 80])
            ->assertJsonValidationErrors(['accuracy' => 'Position trop imprécise (± 80 m, 50 m au plus) : réessayez à découvert.']);

        $this->point($this->assignmentOf('Serge Aka'), 'depart')
            ->assertJsonValidationErrors(['type' => 'Pointez d’abord l’arrivée de Serge Aka.']);

        $this->point($kouame, overrides: ['device_recorded_at' => now()->addHour()->toIso8601String()])
            ->assertJsonValidationErrors(['device_recorded_at' => 'L’heure du pointage est dans le futur : vérifiez l’heure du téléphone.']);
    }

    public function test_out_of_zone_and_late_arrivals_raise_anomalies_without_blocking(): void
    {
        $this->site->forceFill(['work_start_time' => '07:30', 'work_end_time' => '17:30'])->save();
        $this->onWorkDay(time: '08:05');

        $this->point($this->assignmentOf('Touré Ali'), overrides: ['latitude' => 5.38214, 'longitude' => -3.95823])
            ->assertCreated()
            ->assertJsonPath('data.rows.1.arrival.out_of_zone', true)
            ->assertJsonPath('data.rows.1.anomalies.*.value', ['hors_zone', 'retard'])
            ->assertJsonPath('data.rows.1.anomalies.1.comment', 'Arrivée à 08:05 (début prévu 07:30)');

        $this->travelTo(now()->setTimeFromTimeString('16:00'));
        $this->point($this->assignmentOf('Touré Ali'), 'depart')->assertCreated();

        $this->assertEqualsCanonicalizing(
            [AttendanceAnomalyType::HorsZone, AttendanceAnomalyType::Retard, AttendanceAnomalyType::DepartAnticipe],
            AttendanceAnomaly::query()->pluck('anomaly_type')->all(),
        );
    }

    public function test_the_day_is_validated_once_every_worker_has_left_or_is_absent(): void
    {
        $this->onWorkDay();
        $kouame = $this->assignmentOf('Kouamé Koffi');
        $this->point($kouame)->assertCreated();

        $this->travelTo(now()->setTimeFromTimeString('17:34'));
        $this->point($kouame, 'depart')
            ->assertCreated()
            ->assertJsonPath('message', 'Départ validé à 17:34')
            ->assertJsonPath('data.rows.0.state.value', 'termine')
            ->assertJsonPath('data.rows.0.state.label', '08:00 → 17:34 · 8 h 35')
            ->assertJsonPath('data.rows.0.worked_minutes', 515);

        $date = today()->toDateString();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$date}/validate")
            ->assertJsonValidationErrors(['date' => 'Pointez le départ ou l’absence de chaque ouvrier : 2 pointages à compléter.']);

        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Touré Ali')->id])
            ->assertOk()
            ->assertJsonPath('message', 'Touré Ali est marqué absent.');
        $sheet = $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])
            ->assertJsonPath('data.stats', ['present' => 1, 'total' => 3, 'absent' => 2, 'pending' => 0])
            ->assertJsonPath('data.can_validate', true)
            ->json('data');
        $this->assertSame('Montant de la journée : '.number_format($kouame->promoter_daily_rate, 0, ',', "\u{00A0}")."\u{00A0}FCFA", $sheet['validation_hint']);

        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$date}/validate")
            ->assertOk()
            ->assertJsonPath('message', 'Journée du '.today()->locale('fr')->translatedFormat('j M').' validée')
            ->assertJsonPath('data.validated', true)
            ->assertJsonPath('data.amount', $kouame->promoter_daily_rate)
            ->assertJsonPath('data.rows.0.actions', []);

        $this->assertSame(WorkDayStatus::Valide, WorkDay::query()->where('assignment_id', $kouame->id)->sole()->status);
        $mission = $kouame->mission->refresh();
        $this->assertSame(1, $mission->validated_days);
        $this->assertEquals(10.0, (float) $mission->calendar_progress);

        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$date}/validate")
            ->assertJsonValidationErrors(['date' => 'La journée du '.today()->locale('fr')->translatedFormat('j M Y').' est déjà validée.']);
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $kouame->id])
            ->assertJsonValidationErrors(['date']);
    }

    public function test_the_history_lists_past_days_and_presence_rates(): void
    {
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertCreated();
        $this->travelTo(now()->setTimeFromTimeString('17:00'));
        $this->point($this->assignmentOf('Kouamé Koffi'), 'depart')->assertCreated();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Touré Ali')->id])->assertOk();

        $this->onWorkDay(1);
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/attendance/history")
            ->assertOk()
            ->assertJsonPath('meta.days', 1)
            ->assertJsonPath('data.0.validated', false)
            ->assertJsonPath('data.0.present', 1)
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.0.presence_rate', 50)
            ->assertJsonPath('data.0.rows.0.line', '08:00 → 17:00 · 8 h 00')
            ->assertJsonPath('data.0.rows.1.line', 'Absent')
            ->assertJsonPath('meta.rates.*.presence_rate', [100, 0]);

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.site.status.value', 'en_cours')
            ->assertJsonPath('data.kpis.duration_days', 10)
            ->assertJsonPath('data.attendance.stats.pending', 3)
            ->assertJsonPath('data.team_count', 3);
    }

    public function test_the_team_lists_the_assigned_workers_without_their_phone(): void
    {
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/team")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.*.name', ['Kouamé Koffi', 'Touré Ali', 'Serge Aka'])
            ->assertJsonPath('data.0.trade.code', 'macon')
            ->assertJsonPath('data.0.can_replace', true)
            ->assertJsonPath('data.0.replacement', null)
            ->assertJsonMissingPath('data.0.phone')
            ->assertJsonPath('meta.replacement_reasons.*.code', ['absences_repetees', 'qualite', 'indisponibilite', 'autre']);
    }

    public function test_attendance_of_other_promoters_is_out_of_reach(): void
    {
        $this->actingAsPromoter(User::factory()->promoter()->create());

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/attendance")->assertNotFound();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/tracking")->assertNotFound();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/team")->assertNotFound();
    }
}
