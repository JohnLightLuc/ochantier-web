<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Rapports.
 */
class ReportAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $finance;

    private string $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->finance = Admin::factory()->role('finance')->create();
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'))->assertSuccessful();
        $this->onWorkDay(0, '17:00');
        $this->point($this->assignmentOf('Kouamé Koffi'), 'depart')->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'), 'depart')->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$this->startDate->toDateString()}/validate")->assertOk();
        $this->query = 'from='.$this->startDate->startOfMonth()->toDateString().'&to='.$this->startDate->endOfMonth()->toDateString();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    public function test_the_overview_shows_indicators_and_twelve_months(): void
    {
        $this->as($this->finance)->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonCount(4, 'data.kinds')
            ->assertJsonCount(12, 'data.months')
            ->assertJsonPath('data.indicators.sites_active', fn (int $count) => $count >= 0);
    }

    public function test_the_mission_report_counts_validated_days(): void
    {
        $mission = $this->assignmentOf('Kouamé Koffi')->mission;
        $rows = $this->as($this->finance)->getJson("/api/v1/admin/reports/missions?{$this->query}")->assertOk()->assertJsonPath('data.title', 'Rapport des missions')->collect('data.rows');

        $row = $rows->firstWhere('reference', $mission->reference);
        $this->assertSame(2, $row['days']);
        $this->assertSame($this->site->label, $row['site']);
        $this->assertSame($this->assignmentOf('Kouamé Koffi')->promoter_daily_rate + $this->assignmentOf('Touré Ali')->promoter_daily_rate, $row['amount']);
    }

    public function test_site_and_worker_reports_cover_the_period(): void
    {
        $this->as($this->finance)->getJson("/api/v1/admin/reports/chantiers?{$this->query}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.label', $this->site->label)
            ->assertJsonPath('data.rows.0.days', 2)
            ->assertJsonPath('data.rows.0.workers', 2);

        $this->getJson("/api/v1/admin/reports/ouvriers?{$this->query}")
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.days', 1)
            ->assertJsonPath('data.indicators.0.value', 2);

        $this->getJson('/api/v1/admin/reports/ouvriers?from=2020-01-01&to=2020-01-31')->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_the_financial_report_matches_finance(): void
    {
        $finance = $this->as($this->finance)->getJson("/api/v1/admin/finance/overview?{$this->query}")->assertOk()->json('data');
        $report = $this->getJson("/api/v1/admin/reports/financier?{$this->query}")->assertOk()->json('data');

        $this->assertSame($finance['inflows']['total'], $report['indicators'][0]['value']);
        $this->assertSame($finance['revenue'], $report['indicators'][3]['value']);
        $this->assertSame($finance['commissions']['earned'], array_sum(array_column($report['rows'], 'earned')));
    }

    public function test_a_period_spanning_months_gives_one_line_each_without_overlap(): void
    {
        $from = $this->startDate->startOfMonth()->subMonthNoOverflow();
        $rows = $this->as($this->finance)->getJson('/api/v1/admin/reports/financier?from='.$from->addDays(14)->toDateString().'&to='.$this->startDate->toDateString())->assertOk()->json('data.rows');

        $this->assertCount(2, $rows);
        $this->assertSame([$from->format('Y-m'), $this->startDate->format('Y-m')], array_column($rows, 'period'));
    }

    public function test_the_export_is_a_csv_of_the_report(): void
    {
        $csv = $this->as($this->finance)->get("/api/v1/admin/reports/chantiers/export?{$this->query}")->assertOk()->streamedContent();

        $this->assertStringContainsString($this->site->label, $csv);
        $this->assertStringContainsString('Journées validées', $csv);
    }

    public function test_the_financial_report_needs_finance_rights(): void
    {
        $operations = Admin::factory()->role('operations')->create();

        $this->as($operations)->getJson('/api/v1/admin/reports')->assertOk()->assertJsonCount(3, 'data.kinds')->assertJsonCount(0, 'data.months');
        $this->getJson('/api/v1/admin/reports/missions')->assertOk();
        $this->getJson('/api/v1/admin/reports/financier')->assertForbidden();
        $this->get('/api/v1/admin/reports/financier/export', ['Accept' => 'application/json'])->assertForbidden();

        $this->as(Admin::factory()->role('support')->create())->getJson('/api/v1/admin/reports')->assertForbidden();
    }
}
