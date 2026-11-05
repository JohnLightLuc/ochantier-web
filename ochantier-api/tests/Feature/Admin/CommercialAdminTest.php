<?php

namespace Tests\Feature\Admin;

use App\Enums\CommercialEarningStatus;
use App\Enums\CommercialObjectiveIndicator;
use App\Enums\CommercialProfileStatus;
use App\Enums\UserStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommercialEarning;
use App\Models\CommercialProfile;
use App\Models\Notification;
use App\Models\Worker;
use App\Support\Admin\CommercialDirectory;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Commerciaux : liste, indicateurs du mois, export, fiche, suspension, réactivation et
 * fin de contrat.
 */
class CommercialAdminTest extends CommercialTestCase
{
    private Admin $admin;

    private CommercialProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->admin = Admin::factory()->role('operations')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
        $this->profile = $this->commercial->commercialProfile;
        $this->commercial->update(['first_name' => 'Adama', 'last_name' => 'Coulibaly', 'last_login_at' => now()]);
    }

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? $this->admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function submit(string $phone): Worker
    {
        $this->actingAsCommercial($this->commercial);
        $id = $this->readyProspect(['phone_number' => $phone]);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();

        return Worker::query()->where('prospect_id', $id)->sole();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/admin/commercials/{$this->profile->id}{$suffix}";
    }

    public function test_the_list_shows_the_month_indicators_like_the_application(): void
    {
        $validated = $this->submit('0700000091');
        $validated->update(['verification_status' => WorkerVerificationStatus::Valide, 'validated_at' => now()]);
        $refused = $this->submit('0700000092');
        $refused->update(['verification_status' => WorkerVerificationStatus::Refuse]);
        $this->profile->commercialObjectives()->create(['period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'indicator' => CommercialObjectiveIndicator::OuvriersValides, 'target_value' => 4]);
        CommercialEarning::query()->where('worker_id', $validated->id)->update(['status' => CommercialEarningStatus::Acquis, 'event_at' => now()]);
        $commission = (int) CommercialEarning::query()->where('worker_id', $validated->id)->value('amount');

        $response = $this->asAdmin()->getJson('/api/v1/admin/commercials')->assertOk();

        $response->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.full_name', 'Adama Coulibaly')
            ->assertJsonPath('data.0.status', ['value' => 'actif', 'label' => 'Actif'])
            ->assertJsonPath('data.0.workers.total', 2)
            ->assertJsonPath('data.0.workers.month_registered', 2)
            ->assertJsonPath('data.0.workers.month_validated', 1)
            ->assertJsonPath('data.0.validation_rate', 50)
            ->assertJsonPath('data.0.objective', ['target' => 4, 'achieved' => 1, 'percent' => 25])
            ->assertJsonPath('data.0.commissions.month', $commission)
            ->assertJsonPath('data.0.commissions.unpaid', $commission)
            ->assertJsonPath('meta.counts.statuses.actif', 1)
            ->assertJsonPath('meta.counts.workers', 2)
            ->assertJsonPath('meta.counts.unpaid', $commission)
            ->assertJsonPath('meta.counts.todo.gains', 1)
            ->assertJsonPath('meta.counts.todo.objectifs', 0);

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/objectives')->assertOk()->assertJsonPath('data.current.achieved', 1);
    }

    public function test_the_list_filters_and_sorts(): void
    {
        $pending = CommercialProfile::factory()->create(['employee_number' => 'COM-09999', 'supervisor_id' => $this->profile->id]);
        $pending->user->update(['first_name' => 'Aïcha', 'last_name' => 'Touré', 'must_change_password' => true]);
        $this->profile->update(['is_supervisor' => true]);
        $commune = $this->commune('Cocody');
        $this->profile->commercialZones()->create(['commune_id' => $commune, 'is_primary' => true]);

        $this->asAdmin();
        $this->getJson('/api/v1/admin/commercials?status=en_attente_activation')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee_number', 'COM-09999');
        $this->getJson('/api/v1/admin/commercials?search=COM-09999')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/commercials?search=aïcha touré')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/admin/commercials?commune_id={$commune}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.zones.0.name', 'Cocody');
        $this->getJson("/api/v1/admin/commercials?supervisor_id={$this->profile->id}")->assertJsonPath('data.0.supervisor.full_name', 'Adama Coulibaly');
        $this->getJson('/api/v1/admin/commercials?todo=relances')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.last_name', 'Touré');
        $this->getJson('/api/v1/admin/commercials?period=semaine')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.last_name', 'Coulibaly');
        $this->getJson('/api/v1/admin/commercials?period=inactifs')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.last_name', 'Touré');
        $this->getJson('/api/v1/admin/commercials?sort=name&direction=desc')->assertJsonPath('data.0.last_name', 'Touré');

        foreach (array_keys(CommercialDirectory::SORTS) as $sort) {
            $this->getJson("/api/v1/admin/commercials?sort={$sort}")->assertOk()->assertJsonPath('meta.total', 2);
        }

        $this->getJson('/api/v1/admin/commercials?status=inconnu')->assertJsonValidationErrors(['status']);
    }

    public function test_the_export_is_audited(): void
    {
        $this->asAdmin();
        $csv = $this->get('/api/v1/admin/commercials/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Adama Coulibaly', $csv);
        $this->assertStringContainsString('Matricule;Nom', $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'commercials.exported')->exists());
    }

    public function test_the_export_can_be_limited_to_a_selection(): void
    {
        CommercialProfile::factory()->create()->user->update(['first_name' => 'Autre', 'last_name' => 'Personne']);

        $this->asAdmin();
        $csv = $this->get("/api/v1/admin/commercials/export?ids[]={$this->profile->id}")->assertOk()->streamedContent();

        $this->assertStringContainsString('Adama Coulibaly', $csv);
        $this->assertStringNotContainsString('Autre Personne', $csv);
    }

    public function test_the_fiche_shows_the_account_and_its_history(): void
    {
        $worker = $this->submit('0700000093');
        $worker->update(['verification_status' => WorkerVerificationStatus::Valide, 'validated_at' => now()]);

        $this->asAdmin()->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.employee_number', $this->profile->employee_number)
            ->assertJsonPath('data.ranking.rank', 1)
            ->assertJsonPath('data.months.5.registered', 1)
            ->assertJsonPath('data.months.5.validated', 1)
            ->assertJsonPath('data.trades.0.count', 1)
            ->assertJsonPath('data.suspension', null)
            ->assertJsonPath('data.can', ['suspend' => true, 'reactivate' => false, 'terminate' => true, 'edit' => true, 'send_link' => true]);

        $this->getJson('/api/v1/admin/commercials/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_a_suspension_closes_the_sessions_and_a_reactivation_reopens_them(): void
    {
        $this->actingAsCommercial($this->commercial);
        $token = $this->openSession($this->commercial)->accessToken;

        $this->asAdmin()->postJson($this->url('/suspension'), ['reason' => 'Profils dupliqués.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'suspendu')
            ->assertJsonPath('data.suspension.reason', 'Profils dupliqués.')
            ->assertJsonPath('data.suspension.by', 'Awa Koné')
            ->assertJsonPath('data.ranking', null)
            ->assertJsonPath('data.can', ['suspend' => false, 'reactivate' => true, 'terminate' => true, 'edit' => true, 'send_link' => false]);

        $this->assertSame(CommercialProfileStatus::Suspendu, $this->profile->fresh()->status);
        $this->assertSame(UserStatus::Suspendu, $this->commercial->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'commercial.suspended')->where('subject_id', $this->profile->id)->exists());
        $this->assertTrue(Notification::query()->where('event_code', 'account.suspended')->where('recipient_id', $this->commercial->id)->exists());

        $this->withAccessToken($token)->getJson('/api/v1/commercial/home')->assertUnauthorized();

        $this->asAdmin();
        $this->postJson($this->url('/suspension'), ['reason' => 'Une seconde fois.'])->assertUnprocessable()->assertJsonValidationErrors(['commercial']);
        $this->postJson($this->url('/reactivation'))->assertOk()->assertJsonPath('data.status.value', 'actif')->assertJsonPath('data.suspension', null);

        $this->assertSame(UserStatus::Actif, $this->commercial->fresh()->status);
        $this->assertNull($this->commercial->fresh()->suspended_reason);
        $this->postJson($this->url('/reactivation'))->assertUnprocessable()->assertJsonValidationErrors(['commercial']);
    }

    public function test_ending_a_contract_keeps_the_workers_and_can_be_undone(): void
    {
        $worker = $this->submit('0700000094');

        $this->asAdmin()->postJson($this->url('/termination'), ['reason' => 'Fin de contrat · échéance.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'termine')
            ->assertJsonPath('data.ended_at', today()->toDateString())
            ->assertJsonPath('data.termination_reason', 'Fin de contrat · échéance.')
            ->assertJsonPath('data.can', ['suspend' => false, 'reactivate' => true, 'terminate' => false, 'edit' => false, 'send_link' => false]);

        $this->assertSame(UserStatus::Desactive, $this->commercial->fresh()->status);
        $this->assertSame($this->profile->id, $worker->fresh()->current_commercial_id);
        $this->postJson($this->url('/termination'), ['reason' => 'Une seconde fois.'])->assertUnprocessable();

        $this->postJson('/api/v1/auth/commercial/login', ['dial_code' => '225', 'phone_number' => '0500000001', 'password' => 'motdepasse1'])->assertForbidden();

        $this->postJson($this->url('/reactivation'))->assertOk()->assertJsonPath('data.status.value', 'actif')->assertJsonPath('data.ended_at', null);
        $this->assertSame(CommercialProfileStatus::Actif, $this->profile->fresh()->status);
    }

    public function test_a_pending_account_cannot_be_suspended_but_can_be_ended(): void
    {
        $this->commercial->update(['must_change_password' => true]);

        $this->asAdmin()->postJson($this->url('/suspension'), ['reason' => 'Motif assez long.'])->assertUnprocessable();
        $this->postJson($this->url('/termination'), ['reason' => 'Compte créé par erreur.'])->assertOk();
    }

    public function test_workers_registered_by_the_back_office_do_not_count_in_the_commercial_results(): void
    {
        $recruited = $this->submit('0700000095');
        $recruited->update(['verification_status' => WorkerVerificationStatus::Valide, 'validated_at' => now()]);
        $referred = $this->submit('0700000096');
        $referred->update(['verification_status' => WorkerVerificationStatus::Valide, 'validated_at' => now(), 'acquisition_channel' => Worker::BACK_OFFICE_CHANNEL]);
        $this->profile->commercialObjectives()->create(['period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'indicator' => CommercialObjectiveIndicator::OuvriersValides, 'target_value' => 2]);

        $this->asAdmin()->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.workers.total', 1)
            ->assertJsonPath('data.workers.month_validated', 1)
            ->assertJsonPath('data.objective.achieved', 1)
            ->assertJsonPath('data.months.5.registered', 1)
            ->assertJsonPath('data.months.5.validated', 1)
            ->assertJsonPath('data.trades.0.count', 1);

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/objectives')->assertJsonPath('data.current.achieved', 1);
        $this->getJson('/api/v1/commercial/ranking')->assertJsonPath('data.validated', 1);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $reader = Admin::factory()->role('finance')->create();
        $none = Admin::factory()->create();

        $this->asAdmin($none)->getJson('/api/v1/admin/commercials')->assertForbidden();
        $this->asAdmin($reader)->getJson('/api/v1/admin/commercials')->assertOk();
        $this->postJson($this->url('/suspension'), ['reason' => 'Motif assez long.'])->assertForbidden();
        $this->postJson($this->url('/termination'), ['reason' => 'Motif assez long.'])->assertForbidden();
        $this->get('/api/v1/admin/commercials/export')->assertForbidden();
    }
}
