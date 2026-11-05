<?php

namespace Tests\Feature\Admin;

use App\Enums\ProspectStatus;
use App\Enums\WorkerVerificationStatus;
use App\Enums\ZoneRequestStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommercialEarning;
use App\Models\CommercialProfile;
use App\Models\CommercialZone;
use App\Models\Notification;
use App\Models\Prospect;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCommercialTransfer;
use App\Support\Admin\CommercialPortfolio;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Commercial\CommercialNotifier;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Commerciaux : ouvriers suivis et transferts, prospects, zones, demandes de changement
 * et objectifs.
 */
class CommercialTerritoryAdminTest extends CommercialTestCase
{
    private Admin $admin;

    private CommercialProfile $profile;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->admin = Admin::factory()->role('operations')->create();
        $this->profile = $this->commercial->commercialProfile;
        $this->colleague = User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0500000002']);
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

    private function zone(string $name, bool $primary = false): void
    {
        $this->profile->commercialZones()->create(['commune_id' => $this->commune($name), 'is_primary' => $primary, 'effective_from' => now()->subMonth()->toDateString()]);
    }

    public function test_a_transfer_moves_the_follow_up_but_keeps_the_results_with_the_recruiter(): void
    {
        $worker = $this->submit('0700000081');
        $other = $this->submit('0700000082');
        $to = $this->colleague->commercialProfile;

        $this->asAdmin()->postJson($this->url('/workers/transfer'), ['to_commercial_id' => $to->id, 'worker_ids' => [$worker->id], 'reason' => 'Changement de secteur.'])
            ->assertOk()->assertJsonPath('data', ['workers' => 1, 'prospects' => 0]);

        $worker->refresh();
        $this->assertSame($to->id, $worker->current_commercial_id);
        $this->assertSame($this->profile->id, $worker->origin_commercial_id);
        $this->assertSame($to->id, Prospect::query()->whereKey($worker->prospect_id)->value('commercial_id'));
        $this->assertSame($this->profile->id, $other->fresh()->current_commercial_id);
        $this->assertSame(1, WorkerCommercialTransfer::query()->where('worker_id', $worker->id)->count());

        $this->getJson($this->url('/workers'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.transfers.0.direction', 'sortant')->assertJsonPath('meta.transfers.0.reason', 'Changement de secteur.');

        // Le nouveau référent voit l’ouvrier ; l’objectif et la commission restent au recruteur.
        $this->actingAsCommercial($this->colleague);
        $this->getJson('/api/v1/commercial/workers')->assertOk()->assertJsonPath('meta.total', 1);

        $this->asAdmin();
        $worker->update(['verification_status' => WorkerVerificationStatus::Soumis]);
        $this->assertSame($this->profile->id, CommercialEarning::query()->where('worker_id', $worker->id)->value('commercial_id'));

        $this->postJson("/api/v1/admin/workers/{$worker->id}/corrections", ['message' => 'À revoir', 'corrections' => [['field' => 'name', 'reason' => 'Nom incomplet']]]);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->colleague->id)->where('event_code', 'worker.correction')->exists());
        $this->assertFalse(Notification::query()->where('recipient_id', $this->commercial->id)->where('event_code', 'worker.correction')->exists());
    }

    public function test_a_validation_after_a_transfer_tells_the_recruiter_about_the_commission(): void
    {
        $worker = $this->submit('0700000083');
        $worker->forceFill(['current_commercial_id' => $this->colleague->commercialProfile->id])->save();

        app(CommercialNotifier::class)->validated($worker->fresh(), 5000);

        $this->assertStringNotContainsString('Commission', Notification::query()->where('recipient_id', $this->colleague->id)->where('event_code', 'worker.validated')->sole()->body);
        $this->assertStringContainsString('Commission', Notification::query()->where('recipient_id', $this->commercial->id)->where('event_code', 'worker.validated')->sole()->body);
    }

    public function test_the_whole_portfolio_moves_with_the_open_prospects_even_from_an_ended_contract(): void
    {
        $worker = $this->submit('0700000084');
        $open = $this->createProspect(['phone_number' => '0700000085']);
        $abandoned = $this->createProspect(['phone_number' => '0700000086']);
        Prospect::query()->whereKey($abandoned)->update(['status' => ProspectStatus::Abandonne]);
        $to = $this->colleague->commercialProfile;

        $this->asAdmin()->postJson($this->url('/termination'), ['reason' => 'Fin de contrat.'])->assertOk();
        $this->postJson($this->url('/workers/transfer'), ['to_commercial_id' => $to->id, 'reason' => 'Départ du commercial.'])
            ->assertOk()->assertJsonPath('data', ['workers' => 1, 'prospects' => 1]);

        $this->assertSame($to->id, $worker->fresh()->current_commercial_id);
        $this->assertSame($to->id, Prospect::query()->whereKey($open)->value('commercial_id'));
        $this->assertSame($this->profile->id, Prospect::query()->whereKey($abandoned)->value('commercial_id'));

        $this->postJson($this->url('/workers/transfer'), ['to_commercial_id' => $this->profile->id, 'reason' => 'Vers lui-même.'])->assertJsonValidationErrors(['to_commercial_id']);
    }

    public function test_moving_a_worker_moves_its_prospect_and_records_the_transfer(): void
    {
        $worker = $this->submit('0700000087');
        $to = $this->colleague->commercialProfile;

        app(CommercialPortfolio::class)->move($worker, $to->id, 'Référent modifié.', $this->admin);

        $this->assertSame($to->id, Prospect::query()->whereKey($worker->prospect_id)->value('commercial_id'));
        $this->assertSame(1, WorkerCommercialTransfer::query()->where('to_commercial_id', $to->id)->count());
    }

    public function test_prospects_are_listed_with_their_progress(): void
    {
        $this->createProspect(['phone_number' => '0700000088', 'first_name' => 'Ali']);

        $this->asAdmin()->getJson($this->url('/prospects'))->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.nouveau', 1)
            ->assertJsonPath('data.0.first_name', 'Ali')
            ->assertJsonPath('data.0.last_followup_at', null);
        $this->getJson($this->url('/prospects?status=converti'))->assertJsonPath('meta.total', 0);
    }

    public function test_zones_can_be_added_replaced_and_removed(): void
    {
        $this->zone('Cocody', true);
        $this->asAdmin();

        $this->postJson($this->url('/zones'), ['commune_id' => $this->commune('Yopougon')])->assertOk()->assertJsonPath('data.zones.0.name', 'Cocody')->assertJsonCount(2, 'data.zones');
        $this->postJson($this->url('/zones'), ['commune_id' => $this->commune('Yopougon')])->assertJsonValidationErrors(['commune_id']);

        $this->deleteJson($this->url('/zones/'.$this->commune('Cocody')))->assertUnprocessable()->assertJsonValidationErrors(['commercial']);

        $this->postJson($this->url('/zones'), ['commune_id' => $this->commune('Abobo'), 'is_primary' => true])->assertOk()->assertJsonPath('data.zones.0.name', 'Abobo')->assertJsonCount(3, 'data.zones');

        $this->deleteJson($this->url('/zones/'.$this->commune('Cocody')))->assertOk()->assertJsonCount(2, 'data.zones');
        $this->assertSame(today()->subDay()->toDateString(), CommercialZone::query()->where('commune_id', $this->commune('Cocody'))->sole()->effective_to->toDateString());

        // Attribuée le jour même : retirée sans laisser de trace datée impossible.
        $this->deleteJson($this->url('/zones/'.$this->commune('Yopougon')))->assertOk()->assertJsonCount(1, 'data.zones');
        $this->assertFalse(CommercialZone::query()->where('commune_id', $this->commune('Yopougon'))->exists());
    }

    public function test_a_zone_request_from_the_application_can_be_accepted(): void
    {
        $this->zone('Cocody', true);
        $this->actingAsCommercial($this->commercial);
        $this->postJson('/api/v1/commercial/zone-requests', ['commune_ids' => [$this->commune('Abobo'), $this->commune('Yopougon')]])->assertSuccessful();
        $request = $this->profile->zoneRequests()->sole();

        $this->asAdmin()->getJson($this->url())->assertJsonPath('data.zone_request_pending', true);
        $this->getJson($this->url('/zone-requests'))->assertOk()->assertJsonPath('data.requests.0.status.value', 'en_attente');

        $this->postJson($this->url("/zone-requests/{$request->id}/accept"), ['primary_commune_id' => $this->commune('Cocody')])->assertJsonValidationErrors(['primary_commune_id']);
        $this->postJson($this->url("/zone-requests/{$request->id}/accept"), ['primary_commune_id' => $this->commune('Yopougon')])
            ->assertOk()->assertJsonPath('data.zones.0.name', 'Yopougon')->assertJsonCount(2, 'data.zones')->assertJsonPath('data.zone_request_pending', false);

        $this->assertSame(ZoneRequestStatus::Acceptee, $request->fresh()->status);
        $this->assertTrue(Notification::query()->where('event_code', 'zone.accepted')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'commercial.zones_updated')->exists());
        $this->postJson($this->url("/zone-requests/{$request->id}/refuse"), ['reason' => 'Trop tard pour refuser.'])->assertJsonValidationErrors(['commercial']);
    }

    public function test_a_zone_request_can_be_refused_with_a_reason(): void
    {
        $this->zone('Cocody', true);
        $request = $this->profile->zoneRequests()->create(['commune_ids' => [$this->commune('Abobo')], 'status' => ZoneRequestStatus::EnAttente]);

        $this->asAdmin()->postJson($this->url("/zone-requests/{$request->id}/refuse"), ['reason' => 'Zone déjà couverte.'])->assertOk()->assertJsonCount(1, 'data.zones');

        $this->getJson($this->url('/zone-requests'))->assertJsonPath('data.requests.0.decision_comment', 'Zone déjà couverte.');
        $this->assertTrue(Notification::query()->where('event_code', 'zone.refused')->where('body', 'Zone déjà couverte.')->exists());
    }

    public function test_the_monthly_objective_is_set_changed_and_shown_in_the_application(): void
    {
        $month = now()->format('Y-m');
        $this->asAdmin();

        $this->postJson($this->url('/objectives'), ['month' => $month, 'target' => 8, 'comment' => 'Priorité Cocody'])->assertOk()->assertJsonPath('data.objective.target', 8);
        $this->postJson($this->url('/objectives'), ['month' => $month, 'target' => 10])->assertOk()->assertJsonPath('data.objective.target', 10);
        $this->assertSame(1, $this->profile->commercialObjectives()->count());

        $this->postJson($this->url('/objectives'), ['month' => now()->subMonth()->format('Y-m'), 'target' => 5])->assertJsonValidationErrors(['month']);
        $this->postJson($this->url('/objectives'), ['month' => now()->addMonth()->format('Y-m'), 'target' => 12])->assertOk();

        $this->getJson($this->url('/objectives'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.can_edit', true);
        $this->assertTrue(Notification::query()->where('event_code', 'objective.set')->where('recipient_id', $this->commercial->id)->exists());

        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/objectives')->assertJsonPath('data.current.target', 10);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $reader = Admin::factory()->role('finance')->create();
        $request = $this->profile->zoneRequests()->create(['commune_ids' => [$this->commune('Abobo')], 'status' => ZoneRequestStatus::EnAttente]);

        $this->asAdmin($reader);
        $this->getJson($this->url('/workers'))->assertOk();
        $this->postJson($this->url('/workers/transfer'), [])->assertForbidden();
        $this->postJson($this->url('/zones'), [])->assertForbidden();
        $this->postJson($this->url("/zone-requests/{$request->id}/accept"))->assertForbidden();
        $this->postJson($this->url('/objectives'), [])->assertForbidden();
    }
}
