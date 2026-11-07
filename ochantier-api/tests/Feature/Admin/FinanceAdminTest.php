<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Mission;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\WorkDay;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Finance : vue financière et journal des commissions.
 */
class FinanceAdminTest extends PromoterTestCase
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
        $this->query = 'from='.$this->startDate->subMonth()->toDateString().'&to='.$this->startDate->addMonth()->toDateString();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function mission(): Mission
    {
        return $this->assignmentOf('Kouamé Koffi')->mission;
    }

    /**
     * @return array{y: int, z: int, received: int, collected: int}
     */
    private function expected(): array
    {
        $mission = $this->mission();
        $snapshot = $mission->pricingSnapshot;
        $assignments = [$this->assignmentOf('Kouamé Koffi'), $this->assignmentOf('Touré Ali')];
        $base = collect($assignments)->sum('promoter_daily_rate');
        $net = collect($assignments)->sum('worker_net_daily_rate');
        $received = (int) PaymentAllocation::query()->whereIn('payment_schedule_id', $mission->paymentSchedules()->select('id'))->sum('amount');

        return [
            'y' => (int) round($base * (float) $snapshot->y_rate / 100),
            'z' => $base - $net,
            'received' => $received,
            'collected' => (int) round($received * ($snapshot->promoter_commission_amount + $snapshot->worker_commission_amount) / $snapshot->total_promoter_amount),
        ];
    }

    public function test_the_journal_separates_earned_and_collected_commissions(): void
    {
        $expected = $this->expected();
        $this->assertGreaterThan(0, $expected['y']);
        $this->assertGreaterThan(0, $expected['z']);

        $this->as($this->finance)->getJson("/api/v1/admin/finance/commissions?{$this->query}&search={$this->mission()->reference}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.days', 2)
            ->assertJsonPath('data.0.y_amount', $expected['y'])
            ->assertJsonPath('data.0.z_amount', $expected['z'])
            ->assertJsonPath('data.0.earned', $expected['y'] + $expected['z'])
            ->assertJsonPath('data.0.received', $expected['received'])
            ->assertJsonPath('data.0.collected', $expected['collected'])
            ->assertJsonPath('data.0.remaining', max(0, $expected['y'] + $expected['z'] - $expected['collected']))
            ->assertJsonPath('data.0.site_label', $this->site->label);
    }

    public function test_only_validated_days_count_as_earned(): void
    {
        WorkDay::query()->where('mission_id', $this->mission()->id)->update(['status' => 'rejete']);

        $this->as($this->finance)->getJson("/api/v1/admin/finance/commissions?{$this->query}&search={$this->mission()->reference}")
            ->assertOk()
            ->assertJsonPath('data.0.earned', 0)
            ->assertJsonPath('data.0.collected', $this->expected()['collected']);
    }

    public function test_the_overview_adds_flows_and_excludes_what_failed(): void
    {
        $collected = (int) PaymentTransaction::query()->where('status', 'reussie')->whereIn('purpose', ['acompte', 'echeance'])->sum('amount');
        $this->assertGreaterThan(0, $collected);
        PaymentTransaction::query()->where('status', 'reussie')->first()->replicate()->fill(['reference' => 'ECHEC-1', 'idempotency_key' => 'echec-1'])->forceFill(['status' => 'echouee', 'provider_reference' => null])->save();
        $expected = $this->expected();

        $this->as($this->finance)->getJson("/api/v1/admin/finance/overview?{$this->query}")
            ->assertOk()
            ->assertJsonPath('data.inflows.missions', $collected)
            ->assertJsonPath('data.inflows.total', $collected)
            ->assertJsonPath('data.outflows.total', 0)
            ->assertJsonPath('data.net_cash', $collected)
            ->assertJsonPath('data.commissions.promoter_earned', $this->sumY())
            ->assertJsonPath('data.commissions.collected', $this->sumCollected())
            ->assertJsonPath('data.revenue', $this->sumCollected());
        $this->assertGreaterThan(0, $expected['collected']);
    }

    private function sumY(): int
    {
        return $this->getJson("/api/v1/admin/finance/commissions?{$this->query}&per_page=100")->collect('data')->sum('y_amount');
    }

    private function sumCollected(): int
    {
        return $this->getJson("/api/v1/admin/finance/commissions?{$this->query}&per_page=100")->collect('data')->sum('collected');
    }

    public function test_an_executed_refund_takes_its_share_back(): void
    {
        $mission = $this->mission();
        $before = $this->expected();
        $transaction = PaymentTransaction::query()->where('status', 'reussie')->firstOrFail();
        Refund::query()->create(['reference' => 'REM-TEST', 'mission_id' => $mission->id, 'original_transaction_id' => $transaction->id, 'beneficiary_type' => 'promoter_profile', 'beneficiary_id' => $this->promoter->promoterProfile->id, 'amount' => 10000, 'reason' => 'Test', 'status' => 'execute', 'origin' => 'manuel', 'requested_by_admin_id' => $this->finance->id, 'executed_at' => now()]);
        $snapshot = $mission->pricingSnapshot;
        $share = (int) round(10000 * ($snapshot->promoter_commission_amount + $snapshot->worker_commission_amount) / $snapshot->total_promoter_amount);

        $row = $this->as($this->finance)->getJson("/api/v1/admin/finance/commissions?{$this->query}&search={$mission->reference}")->assertOk()->json('data.0');

        $this->assertSame($share, $row['refunded']);
        $this->assertSame($before['collected'], $row['collected']);
    }

    public function test_the_period_limits_the_journal(): void
    {
        $this->as($this->finance)->getJson('/api/v1/admin/finance/commissions?from=2020-01-01&to=2020-01-31')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/finance/overview?from=2020-01-31&to=2020-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_the_journal_groups_by_site_and_promoter_and_exports(): void
    {
        $this->as($this->finance)->getJson("/api/v1/admin/finance/commissions?{$this->query}&group=site")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->site->id)->assertJsonPath('data.0.days', 2);
        $this->getJson("/api/v1/admin/finance/commissions?{$this->query}&group=promoter")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->promoter->promoterProfile->id);

        $csv = $this->get("/api/v1/admin/finance/commissions/export?{$this->query}")->assertOk()->streamedContent();
        $this->assertStringContainsString($this->mission()->reference, $csv);
    }

    public function test_rights_are_enforced(): void
    {
        $this->as(Admin::factory()->role('support')->create())->getJson('/api/v1/admin/finance/overview')->assertForbidden();
        $this->as(Admin::factory()->role('direction')->create())->getJson('/api/v1/admin/finance/overview')->assertOk();
        $this->as(Admin::factory()->role('lecture_seule')->create())->get('/api/v1/admin/finance/commissions/export', ['Accept' => 'application/json'])->assertForbidden();
    }
}
