<?php

namespace Tests\Feature\Admin;

use App\Enums\CommercialAdjustmentStatus;
use App\Enums\CommercialEarningStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommercialAdjustment;
use App\Models\CommercialEarning;
use App\Models\CommercialProfile;
use App\Models\CommercialRanking;
use App\Models\Notification;
use App\Models\User;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Commerciaux : gains, primes et retenues, appréciations, historique et classement mensuel.
 */
class CommercialCompensationAdminTest extends CommercialTestCase
{
    private Admin $proposer;

    private Admin $approver;

    private CommercialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->proposer = Admin::factory()->role('finance')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
        $this->approver = Admin::factory()->role('direction')->create(['first_name' => 'Yao', 'last_name' => 'Konan']);
        $this->profile = $this->commercial->commercialProfile;
        $this->commercial->update(['first_name' => 'Adama', 'last_name' => 'Coulibaly']);
    }

    private function asAdmin(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/admin/commercials/{$this->profile->id}{$suffix}";
    }

    private function validated(string $phone, ?\DateTimeInterface $at = null): Worker
    {
        $this->actingAsCommercial($this->commercial);
        $id = $this->readyProspect(['phone_number' => $phone]);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();
        $worker = Worker::query()->where('prospect_id', $id)->sole();
        $worker->update(['verification_status' => WorkerVerificationStatus::Valide, 'validated_at' => $at ?? now()]);

        return $worker;
    }

    private function propose(array $overrides = []): CommercialAdjustment
    {
        $this->asAdmin($this->proposer)->postJson($this->url('/adjustments'), ['kind' => 'prime', 'amount' => 25000, 'reason' => 'Commercial du mois.', ...$overrides])->assertCreated();

        return CommercialAdjustment::query()->latest()->firstOrFail();
    }

    public function test_an_approved_adjustment_needs_a_second_person_and_shows_in_the_application(): void
    {
        $adjustment = $this->propose();

        $this->postJson($this->url("/adjustments/{$adjustment->id}/approve"))->assertUnprocessable()->assertJsonValidationErrors(['commercial']);
        $this->getJson($this->url('/earnings'))->assertOk()->assertJsonPath('data.adjustments.0.status.value', 'proposee')->assertJsonPath('data.adjustments.0.proposed_by.full_name', 'Awa Koné')->assertJsonPath('data.adjustments.0.can_decide', false);
        $this->asAdmin($this->approver)->getJson($this->url('/earnings'))->assertJsonPath('data.adjustments.0.can_decide', true);

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/earnings')->assertOk()->assertJsonPath('data.adjustments', []);

        $this->asAdmin($this->approver)->postJson($this->url("/adjustments/{$adjustment->id}/approve"))->assertOk();
        $this->assertSame(CommercialAdjustmentStatus::Approuvee, $adjustment->fresh()->status);
        $this->assertSame($this->approver->id, $adjustment->fresh()->approved_by_admin_id);
        $this->assertTrue(Notification::query()->where('event_code', 'adjustment.approved')->where('recipient_id', $this->commercial->id)->exists());
        $this->postJson($this->url("/adjustments/{$adjustment->id}/refuse"), ['reason' => 'Trop tard pour refuser.'])->assertUnprocessable();

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/earnings')->assertJsonPath('data.adjustments.0.kind.value', 'prime')->assertJsonPath('data.adjustments.0.reason', 'Commercial du mois.');
    }

    public function test_the_earnings_show_the_commissions_of_the_month(): void
    {
        $worker = $this->validated('0700000074');
        CommercialEarning::query()->where('worker_id', $worker->id)->update(['status' => CommercialEarningStatus::Acquis, 'event_at' => now()]);

        $this->asAdmin($this->approver)->getJson($this->url('/earnings'))->assertOk()
            ->assertJsonPath('data.history.0.worker_id', $worker->id)
            ->assertJsonPath('data.history.0.name', trim($worker->first_name.' '.$worker->last_name))
            ->assertJsonPath('data.history.0.status.value', 'confirmee')
            ->assertJsonStructure(['data' => ['month', 'month_label', 'months', 'confirmed', 'paid', 'pending', 'payouts', 'history' => [['event_at', 'amount']]]]);
    }

    public function test_a_refused_adjustment_stays_invisible_to_the_commercial(): void
    {
        $adjustment = $this->propose(['kind' => 'retenue', 'amount' => 5000, 'reason' => 'Dossier dupliqué.']);

        $this->asAdmin($this->approver)->postJson($this->url("/adjustments/{$adjustment->id}/refuse"), ['reason' => 'Doublon non confirmé.'])->assertOk();

        $this->assertSame(CommercialAdjustmentStatus::Refusee, $adjustment->fresh()->status);
        $this->assertFalse(Notification::query()->where('event_code', 'adjustment.approved')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'commercial.adjustment_refused')->exists());

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/earnings')->assertJsonPath('data.adjustments', []);
    }

    public function test_appreciations_stay_internal_and_the_history_lists_the_actions(): void
    {
        $operations = Admin::factory()->role('operations')->create();
        $this->asAdmin($operations)->postJson($this->url('/appreciations'), ['body' => 'Très bon suivi des dossiers.'])->assertCreated();
        $this->getJson($this->url('/appreciations'))->assertOk()->assertJsonPath('data.0.body', 'Très bon suivi des dossiers.');
        $this->postJson($this->url('/suspension'), ['reason' => 'Dossiers dupliqués.'])->assertOk();

        $this->getJson($this->url('/history'))->assertOk()
            ->assertJsonPath('data.0.what', 'a suspendu le compte')
            ->assertJsonPath('data.0.detail', 'Dossiers dupliqués.')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_a_finished_month_can_be_closed_then_published_and_stays_frozen(): void
    {
        $last = now()->subMonthNoOverflow()->startOfMonth();
        $month = $last->format('Y-m');
        $this->validated('0700000071', $last->copy()->addDays(3));
        User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0500000003', 'first_name' => 'Zoé', 'last_name' => 'Yao']);

        $this->asAdmin($this->proposer)->postJson('/api/v1/admin/commercials/ranking/close', ['month' => $month])->assertForbidden();

        $operations = Admin::factory()->role('operations')->create();
        $this->asAdmin($operations)->getJson("/api/v1/admin/commercials/ranking?month={$month}")->assertOk()
            ->assertJsonPath('data.can_close', true)->assertJsonPath('data.closed_at', null)->assertJsonPath('data.rows.0.name', 'Adama Coulibaly')->assertJsonPath('data.rows.0.validated', 1)->assertJsonPath('data.top', null);

        $this->postJson('/api/v1/admin/commercials/ranking/close', ['month' => now()->format('Y-m')])->assertUnprocessable()->assertJsonValidationErrors(['ranking']);
        $this->postJson('/api/v1/admin/commercials/ranking/publish', ['month' => $month])->assertJsonValidationErrors(['ranking']);

        $this->postJson('/api/v1/admin/commercials/ranking/close', ['month' => $month])->assertOk()
            ->assertJsonPath('data.can_close', false)->assertJsonPath('data.can_publish', true)->assertJsonPath('data.top.name', 'Adama Coulibaly');
        $this->postJson('/api/v1/admin/commercials/ranking/close', ['month' => $month])->assertJsonValidationErrors(['ranking']);

        // Un ouvrier validé après coup sur ce mois ne change plus les rangs.
        $this->validated('0700000073', $last->copy()->addDays(4));
        $this->asAdmin($operations)->getJson("/api/v1/admin/commercials/ranking?month={$month}")->assertJsonPath('data.rows.0.validated', 1)->assertJsonPath('data.rows.1.name', 'Zoé Yao');

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/ranking')->assertJsonPath('data.published', []);

        $this->asAdmin($operations)->postJson('/api/v1/admin/commercials/ranking/publish', ['month' => $month])->assertOk()->assertJsonPath('data.can_publish', false);
        $this->postJson('/api/v1/admin/commercials/ranking/publish', ['month' => $month])->assertJsonValidationErrors(['ranking']);
        $this->assertSame(2, Notification::query()->where('event_code', 'ranking.published')->count());
        $this->assertNotNull(CommercialRanking::query()->sole()->published_at);

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/ranking')->assertJsonPath('data.published.0.month', $month)->assertJsonPath('data.published.0.rank', 1);
    }

    public function test_the_ranking_export_lists_the_month(): void
    {
        $this->validated('0700000072');

        $csv = $this->asAdmin($this->approver)->get('/api/v1/admin/commercials/ranking/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Adama Coulibaly', $csv);
        $this->assertStringContainsString('Rang;Matricule;Commercial', $csv);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $support = Admin::factory()->role('support')->create();

        $this->asAdmin($support);
        $this->getJson($this->url('/earnings'))->assertOk();
        $this->postJson($this->url('/adjustments'), [])->assertForbidden();
        $this->postJson($this->url('/appreciations'), ['body' => 'Non autorisé.'])->assertForbidden();
    }
}
