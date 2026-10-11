<?php

namespace Tests\Feature;

use App\Models\AnalyticsReport;
use App\Models\AnalyticsSchedule;
use App\Models\Site;
use App\Models\User;
use App\Support\AnalyticsInsights;
use App\Support\QualityInsights;
use Database\Seeders\AnalyticsSeeder;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\PaymentSeeder;
use Database\Seeders\PromoterSeeder;
use Database\Seeders\QualitySeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
        QualityInsights::forgetPrestations();
        $this->seed([PromoterSeeder::class, WorkerSeeder::class, SiteSeeder::class, AttendanceSeeder::class, PaymentSeeder::class, FinanceSeeder::class, QualitySeeder::class, AnalyticsSeeder::class]);
    }

    public function test_overview_and_report_pages_render(): void
    {
        $this->get('/rapports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Analytics/Page')->has('kpis', 4)->has('lines', 1)->has('donuts', 1)->has('tiles', 3)->has('panels', 2)->has('selects', 4)->has('nav', 6));
        $this->get('/rapports/financier')->assertOk()->assertInertia(fn (Assert $page) => $page->has('kpis', 4)->has('lines', 1)->has('tables', 2));
        $this->get('/rapports/missions')->assertOk()->assertInertia(fn (Assert $page) => $page->has('donuts', 1)->has('tables', 3));
        $this->get('/rapports/chantiers')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tables', 1));
        $this->get('/rapports/ouvriers')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tiles', 3)->has('tables', 2));
        $this->get('/rapports/bibliotheque')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tables.0.rows', 3)->has('tables.1.rows', 4));
        $site = Site::query()->where('slug', 'residence-les-oliviers')->firstOrFail();
        $this->get("/rapports/chantiers/{$site->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('header.title', $site->name)->has('tables', 2));
        $this->get('/rapports/inconnu')->assertNotFound();
    }

    public function test_series_follow_the_rhythm_and_filters(): void
    {
        $monthly = (new AnalyticsInsights)->series();
        $quarterly = (new AnalyticsInsights(['rythme' => 'Trimestriel']))->series();
        $this->assertSame(array_sum($monthly['collected']), array_sum($quarterly['collected']));
        $this->assertSame(['T1 2026', 'T2 2026', 'T3 2026'], $quarterly['labels']);

        $filtered = new AnalyticsInsights(['metier' => 'Électricité']);
        $this->assertTrue($filtered->entries->every(fn (array $x): bool => AnalyticsInsights::family($x['trade']) === 'Électricité'));
        $this->assertSame('Maçonnerie', AnalyticsInsights::family('Maçon coffreur'));
    }

    public function test_reports_are_generated_recorded_and_downloaded(): void
    {
        $csv = $this->get('/rapports/generer?rapport=missions&format=CSV&zone=Cocody');
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Synthèse par famille de métiers', $csv->getContent());
        $report = AnalyticsReport::query()->latest('id')->firstOrFail();
        $this->assertSame('Cocody', $report->filters);

        $this->get('/rapports/generer?rapport=synthese&format=PDF')->assertOk()->assertSee('Évolution financière')->assertSee('window.print', false);
        $this->post('/rapports/bibliotheque', ['kind' => 'chantiers', 'format' => 'Excel'])->assertRedirect('/rapports/bibliotheque');
        $this->get('/rapports/bibliotheque/'.AnalyticsReport::query()->latest('id')->value('id').'/telechargement')->assertOk()->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8');
        $this->get('/rapports/generer?rapport=inconnu&format=CSV')->assertSessionHasErrors('rapport');
    }

    public function test_schedules_can_be_created_suspended_and_deleted(): void
    {
        $this->post('/rapports/programmations', ['kind' => 'missions', 'frequency' => 'Hebdomadaire', 'next_run' => '2026-09-20', 'recipients' => 'Direction', 'format' => 'PDF'])->assertSessionHasErrors('next_run');
        $this->post('/rapports/programmations', ['kind' => 'missions', 'frequency' => 'Hebdomadaire', 'next_run' => '2026-09-30', 'recipients' => 'Direction des opérations', 'format' => 'PDF'])->assertRedirect();
        $schedule = AnalyticsSchedule::query()->latest('id')->firstOrFail();
        $this->assertTrue($schedule->isActive());

        $this->post("/rapports/programmations/{$schedule->id}/statut")->assertRedirect();
        $this->assertSame('Suspendu', $schedule->fresh()->status);
        $this->post("/rapports/programmations/{$schedule->id}/suppression")->assertRedirect();
        $this->assertModelMissing($schedule);
    }
}
