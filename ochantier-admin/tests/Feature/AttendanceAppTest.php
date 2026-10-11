<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Models\Attendance;
use App\Models\Site;
use App\Models\User;
use App\Support\AttendanceCalculator as Calc;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    /**
     * Journée du jour de référence, ouvrier attendu (rien d’enregistré).
     */
    private function expectedToday(array $state = []): Attendance
    {
        return Attendance::factory()->state(['date' => Attendance::referenceDate()->format('Y-m-d'), 'arrival' => Attendance::event('Non enregistrée'), 'departure' => Attendance::event('Non enregistré'), 'pauses' => [], ...$state])->create();
    }

    private function calc(Attendance $p): array
    {
        return Calc::calculate($p->fresh()->load('worker', 'site', 'anomalies', 'corrections'));
    }

    public function test_site_and_worker_views(): void
    {
        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class]);
        $p = Attendance::query()->with('site', 'worker')->first();

        $this->get("/pointages/chantiers/{$p->site->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendances/Site')->has('kpis', 4)->where('data.day', '2026-09-23')->has('data.tiles', 9));
        $this->get("/pointages/chantiers/{$p->site->slug}/semaine")->assertOk()->assertInertia(fn (Assert $page) => $page->has('data.grid'));
        $this->get("/pointages/chantiers/{$p->site->slug}/mois")->assertOk()->assertInertia(fn (Assert $page) => $page->where('data.days.0.date', '2026-09-24'));
        $this->get("/pointages/ouvriers/{$p->worker->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attendances/Worker')->has('rows', 13)->has('tiles', 7));
        $this->get("/pointages/ouvriers/{$p->worker->slug}/affectations")->assertOk()->assertInertia(fn (Assert $page) => $page->has('assignments', 1));
        $this->get('/pointages/chantiers/renovation-hotel-tiama')->assertNotFound();

        $this->get('/pointages/application')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attendances/App')->has('groups', 6));
    }

    public function test_promoter_records_then_validates_arrival_and_departure(): void
    {
        $day = $this->expectedToday();
        $base = "/pointages/{$day->reference}/application";

        $this->post("{$base}/depart", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Fin'])->assertStatus(422);
        $this->post("{$base}/arrivee", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => ''])->assertSessionHasErrors('comment');
        $this->post("{$base}/arrivee", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Présent, EPI portés'])->assertSessionHasNoErrors();
        $this->assertSame('Arrivée en attente', $this->calc($day)['status']);
        $this->assertCount(2, $day->fresh()->arrival['proofs']);
        $this->post("{$base}/arrivee", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Encore'])->assertStatus(422);

        $this->post("{$base}/validation-arrivee")->assertSessionHasNoErrors();
        $this->assertSame('Présent', $this->calc($day)['status']);

        $this->post("{$base}/depart", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Poste rangé'])->assertSessionHasNoErrors();
        $this->post("{$base}/validation-depart")->assertSessionHasNoErrors();
        $c = $this->calc($day);
        $this->assertTrue($c['arrivalValid'] && $c['departureValid']);
        $this->assertNotNull($c['net']);
        $this->assertSame(4, $day->activities()->where('origin', 'Application promoteur')->count());
    }

    public function test_outside_zone_and_missing_photo_raise_issues(): void
    {
        $day = $this->expectedToday();
        $this->post("/pointages/{$day->reference}/application/arrivee", ['zone' => 'hors', 'photo' => 0, 'signature' => 1, 'comment' => 'Pointé depuis le portail'])->assertSessionHasNoErrors();

        $this->assertSame('Hors zone', $day->fresh()->arrival['location']['status']);
        $this->assertDatabaseHas('attendance_anomalies', ['attendance_id' => $day->id, 'type' => 'Pointage hors zone', 'level' => 'Élevée']);
        $this->assertTrue($this->calc($day)['proofMissing']);
    }

    public function test_absence_declaration_and_validation(): void
    {
        $day = $this->expectedToday();
        $base = "/pointages/{$day->reference}/application";

        $this->post("{$base}/absence", ['motif' => 'Maladie', 'comment' => 'Fièvre'])->assertSessionHasNoErrors();
        $this->assertSame('Absence en attente', $this->calc($day)['status']);
        $this->post("{$base}/arrivee", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Arrivé'])->assertStatus(422);
        $this->post("{$base}/validation-absence")->assertSessionHasNoErrors();
        $this->assertSame('Absent', $this->calc($day)['status']);
    }

    public function test_recording_is_refused_outside_today_or_on_a_suspended_site(): void
    {
        $past = Attendance::factory()->state(['arrival' => Attendance::event('Non enregistrée'), 'departure' => Attendance::event('Non enregistré')])->create();
        $this->post("/pointages/{$past->reference}/application/arrivee", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Test'])->assertStatus(422);

        $suspended = $this->expectedToday(['site_id' => Site::factory()->state(['status' => SiteStatus::Suspended])]);
        $this->post("/pointages/{$suspended->reference}/application/arrivee", ['zone' => 'dans', 'photo' => 1, 'signature' => 1, 'comment' => 'Test'])->assertStatus(422);
    }

    public function test_group_recording_reports_each_worker(): void
    {
        $a = $this->expectedToday();
        $b = $this->expectedToday();
        $done = $this->expectedToday(['arrival' => Attendance::event('En attente de validation', ['time' => '07:31'])]);

        $this->post('/pointages/application/groupe', ['refs' => [$a->reference, $b->reference, $done->reference], 'action' => 'arrivee'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('groupResult.summary', '2 réussi(s) · 0 échoué(s) · 1 ignoré(s) · 0 à vérifier');

        $this->assertSame('En attente de validation', $a->fresh()->arrival['status']);
        $this->assertSame('07:31', $done->fresh()->arrival['time']);
    }
}
