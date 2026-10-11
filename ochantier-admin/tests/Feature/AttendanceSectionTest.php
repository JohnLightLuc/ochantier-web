<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class]);
        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    public function test_incomplete_page_lists_days_with_reasons(): void
    {
        $this->get('/pointages/incomplets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Incomplete')
                ->where('period.key', 'mois')
                ->has('tiles', 8)
                ->where('attendances.total', fn (int $n): bool => $n > 0)
                ->where('attendances.data.0.incomplete', fn ($reasons): bool => count($reasons) > 0)
                ->where('sections.2.active', true)
            );

        $csv = $this->get('/pointages/export/incomplets')->assertOk()->streamedContent();
        $this->assertStringContainsString('PT-2026-', $csv);
    }

    public function test_anomalies_page_filters_by_level(): void
    {
        $this->get('/pointages/anomalies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Anomalies')
                ->has('tiles', 6)
                ->where('anomalies.total', fn (int $n): bool => $n > 0)
                ->where('anomalies.data.0.level', 'Critique')
            );

        $this->get('/pointages/anomalies?niveau=Modérée')->assertInertia(fn (Assert $page) => $page->where('anomalies.data', fn ($rows): bool => collect($rows)->every(fn ($a): bool => $a['level'] === 'Modérée')));
    }

    public function test_corrections_page_lists_pending_requests(): void
    {
        $this->get('/pointages/corrections?statut=Soumise')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Corrections')
                ->where('corrections.total', fn (int $n): bool => $n > 0)
                ->where('corrections.data.0.status', 'Soumise')
            );
    }

    public function test_absences_calendar_and_reports(): void
    {
        $this->get('/pointages/absences')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendances/Absences')->has('tiles', 6)->where('absences.data.0.decision.key', 'absence'));

        $this->get('/pointages/calendrier')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendances/Calendar')
                ->has('days', 12)
                ->has('grid', 10)
                ->where('grid.0.cells', fn ($cells): bool => count($cells) === 12)
            );

        $this->get('/pointages/rapports?periode=semaine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Attendances/Reports')->has('tiles', 8)->has('charts', 4)->where('period.key', 'semaine'));

        $hours = $this->get('/pointages/export/heures-ouvriers')->assertOk()->streamedContent();
        $this->assertStringContainsString('Jours complets', $hours);
        $this->get('/pointages/export/inconnu')->assertNotFound();
    }

    public function test_group_reminder_and_closure_skip_days_that_cannot_be_closed(): void
    {
        $open = Attendance::query()->get()->filter(fn (Attendance $p): bool => ! $p->isClosed())->take(5);
        $refs = $open->pluck('reference')->all();

        $this->post('/pointages/relance', ['refs' => $refs])->assertSessionHasNoErrors();
        $this->assertSame(5, Attendance::query()->whereIn('reference', $refs)->sum('reminders'));
        $this->post('/pointages/relance', ['refs' => []])->assertSessionHasErrors('refs');
    }
}
