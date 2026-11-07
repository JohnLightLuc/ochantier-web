<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Claim;
use App\Models\PaymentTransaction;
use App\Models\Suggestion;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office : tableau de bord.
 */
class DashboardAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
    }

    private function as(string $role): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open(Admin::factory()->role($role)->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    public function test_the_dashboard_gathers_each_block(): void
    {
        $data = $this->as('super_admin')->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(['sites', 'workers', 'replacements', 'receivables'], array_column($data['kpis'], 'key'));
        $this->assertSame(1, $data['kpis'][0]['value']);
        $this->assertCount(7, $data['activity']['points']);
        $this->assertSame($this->site->id, $data['recent_sites'][0]['id']);
        $this->assertNotEmpty($data['feed']);
    }

    public function test_finance_counts_only_successful_money_in_its_range(): void
    {
        $this->travelTo(PaymentTransaction::query()->where('status', 'reussie')->latest('completed_at')->first()->completed_at->addDay());
        $total = (int) PaymentTransaction::query()->where('status', 'reussie')->sum('amount');
        $points = $this->as('super_admin')->getJson('/api/v1/admin/dashboard?finance=7j')->assertOk()->json('data.finance.points');

        $this->assertCount(7, $points);
        $this->assertSame($total, array_sum(array_column($points, 'collected')));
    }

    public function test_ranges_are_validated(): void
    {
        $this->as('super_admin')->getJson('/api/v1/admin/dashboard?activity=custom&from=2026-09-01&to=2026-09-03')->assertOk()->assertJsonCount(3, 'data.activity.points');
        $this->getJson('/api/v1/admin/dashboard?activity=nimporte')->assertUnprocessable();
        $this->getJson('/api/v1/admin/dashboard?activity=custom&from=2026-09-21&to=2026-09-01')->assertUnprocessable();
    }

    public function test_blocks_follow_the_rights_of_the_admin(): void
    {
        $data = $this->as('support')->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertNull($data['finance']);
        $this->assertNull($data['matching']);
        $this->assertNotContains('receivables', array_column($data['kpis'], 'key'));
        $this->assertNotContains('payment', array_column($data['feed'], 'kind'));

        $data = $this->as('finance')->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $this->assertNotNull($data['finance']);
    }

    public function test_matching_shows_the_best_current_suggestions(): void
    {
        Suggestion::query()->limit(1)->update(['status' => 'suggere', 'score' => 88]);

        $this->as('super_admin')->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.matching.relevance', 88)
            ->assertJsonPath('data.matching.profiles.0.score', 88)
            ->assertJsonPath('data.matching.profiles.0.site', $this->site->label);
    }

    public function test_priorities_and_badges_count_what_waits_for_an_action(): void
    {
        $claim = Claim::query()->create(['reference' => 'REC-TEST', 'claimant_type' => 'promoter_profile', 'claimant_id' => $this->promoter->promoterProfile->id, 'category' => 'autre', 'subject' => 'En retard', 'description' => 'Réponse attendue.', 'due_at' => now()->subDay(), 'status' => 'ouverte']);
        $this->assertNotNull($claim);

        $this->as('support')->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.priorities.0', ['key' => 'claims', 'count' => 1, 'priority' => 'Urgent']);
        $this->getJson('/api/v1/admin/dashboard/badges')->assertOk()->assertJsonPath('data.badges.avis', 1);
        $this->as('operations')->getJson('/api/v1/admin/dashboard/badges')->assertOk()->assertJsonPath('data.badges.avis', 1);
        $this->as('lecture_seule')->getJson('/api/v1/admin/dashboard/badges')->assertOk();
    }
}
