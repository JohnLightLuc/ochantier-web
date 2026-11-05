<?php

namespace Tests\Feature\Admin;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\WorkDay;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\Reservations;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Chantiers : liste, compteurs, export, fiche, suspension, reprise, annulation, clôture et
 * archivage.
 */
class SiteAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/admin/sites/{$this->site->id}{$suffix}";
    }

    public function test_the_list_shows_the_staffing_and_the_counters(): void
    {
        $this->confirmSite();

        $this->asAdmin()->getJson('/api/v1/admin/sites')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reference', $this->site->reference)
            ->assertJsonPath('data.0.status.value', 'confirme')
            ->assertJsonPath('data.0.staffing.assigned', 3)
            ->assertJsonPath('data.0.is_late', false)
            ->assertJsonPath('meta.counts.active', 1)
            ->assertJsonPath('meta.counts.statuses.confirme', 1)
            ->assertJsonPath('meta.counts.todo.retard', 0);

        foreach (['status=confirme', 'status=annule', 'search='.$this->site->reference, 'period=a_venir', 'todo=effectif', 'sort=label', 'sort=start', 'sort=end', 'sort=staffing&direction=desc', 'sort=status', 'priority=normale', 'archived=1', 'todo=retard', 'todo=cloturer', 'todo=incidents', 'todo=pointages', 'period=mois', 'period=fin_60'] as $query) {
            $this->getJson('/api/v1/admin/sites?'.$query)->assertOk();
        }

        $this->getJson('/api/v1/admin/sites?search='.$this->site->reference)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/sites?status=annule')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/sites?status=inconnu')->assertJsonValidationErrors(['status']);
    }

    public function test_a_site_past_its_end_date_is_late_and_ready_to_close(): void
    {
        $this->confirmSite();
        $this->site->update(['planned_end_date' => now()->subDay()->toDateString(), 'planned_start_date' => now()->subDays(30)->toDateString()]);

        $this->asAdmin()->getJson('/api/v1/admin/sites?todo=retard')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_late', true);
        $this->getJson('/api/v1/admin/sites?todo=cloturer')->assertJsonPath('meta.total', 1);
    }

    public function test_the_export_and_the_fiche(): void
    {
        $this->confirmSite();

        $this->asAdmin();
        $csv = $this->get('/api/v1/admin/sites/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($this->site->reference, $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'sites.exported')->exists());

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.needs.0.trade', 'Maçon')
            ->assertJsonPath('data.can', ['suspend' => true, 'resume' => false, 'cancel' => false, 'close' => true, 'archive' => false]);
        $this->getJson('/api/v1/admin/sites/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_a_suspension_blocks_attendance_and_reservations_until_it_is_lifted(): void
    {
        $this->confirmSite();
        $promoter = $this->promoter;
        $assignment = $this->assignmentOf('Kouamé Koffi');
        $this->onWorkDay();

        $this->asAdmin()->postJson($this->url('/suspension'), ['reason' => 'Arrêt de sécurité sur le site.'])->assertOk()
            ->assertJsonPath('data.status.value', 'suspendu')
            ->assertJsonPath('data.suspension_reason', 'Arrêt de sécurité sur le site.')
            ->assertJsonPath('data.can.resume', true);

        $this->assertSame(AssignmentStatus::Confirmee, $assignment->fresh()->status);
        $this->assertTrue(Notification::query()->where('event_code', 'site.suspended')->where('recipient_id', $promoter->id)->exists());

        $this->actingAsPromoter($promoter);
        $this->point($assignment)->assertUnprocessable()->assertJsonValidationErrors(['assignment_id']);

        try {
            app(Reservations::class)->createAdditional($this->site->fresh(), $promoter, []);
            $this->fail('Un chantier suspendu ne doit pas accepter de réservation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('site', $e->errors());
        }

        $this->asAdmin()->postJson($this->url('/suspension'), ['reason' => 'Une seconde fois.'])->assertJsonValidationErrors(['site']);
        $this->deleteJson($this->url('/suspension'))->assertOk()->assertJsonPath('data.status.value', 'confirme');
        $this->deleteJson($this->url('/suspension'))->assertJsonValidationErrors(['site']);

        $this->actingAsPromoter($promoter);
        $this->point($assignment)->assertCreated();
    }

    public function test_only_a_site_without_payment_can_be_cancelled(): void
    {
        $this->acceptProposal();
        $this->site->refresh();
        $this->assertSame(SiteStatus::Paiement, $this->site->status);

        $this->asAdmin()->postJson($this->url('/cancellation'), ['reason' => 'Demande du promoteur.'])->assertJsonValidationErrors(['site']);

        $this->site->update(['status' => SiteStatus::Recherche]);
        $this->site->proposals()->update(['status' => ProposalStatus::AValider]);
        $this->postJson($this->url('/cancellation'), ['reason' => 'Demande du promoteur.'])->assertOk()
            ->assertJsonPath('data.status.value', 'annule')
            ->assertJsonPath('data.cancellation_reason', 'Demande du promoteur.')
            ->assertJsonPath('data.can.archive', true);

        $this->assertSame(OfferStatus::Annulee, $this->site->offers()->first()->status);
        $this->assertSame(ProposalStatus::Annulee, $this->site->proposals()->first()->status);
        $this->assertTrue(Notification::query()->where('event_code', 'site.cancelled')->exists());
    }

    public function test_closing_needs_validated_days_ends_the_missions_and_opens_the_ratings(): void
    {
        $this->confirmSite();
        $assignment = $this->assignmentOf('Kouamé Koffi');
        $day = WorkDay::query()->create(['assignment_id' => $assignment->id, 'mission_id' => $assignment->mission_id, 'worker_id' => $assignment->worker_id, 'work_date' => today()->toDateString(), 'status' => WorkDayStatus::AValider]);

        $admin = Admin::factory()->role('operations')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
        $this->asAdmin($admin)->postJson($this->url('/closure'))->assertUnprocessable()->assertJsonValidationErrors(['site']);

        $day->update(['status' => WorkDayStatus::Rejete]);
        $this->postJson($this->url('/closure'))->assertOk()
            ->assertJsonPath('data.status.value', 'termine')
            ->assertJsonPath('data.closed_by', 'Awa Koné')
            ->assertJsonPath('data.can.archive', true);

        $this->assertSame(MissionStatus::Terminee, $assignment->mission->fresh()->status);
        $this->assertSame(AssignmentStatus::Terminee, $assignment->fresh()->status);
        $this->assertTrue(Notification::query()->where('event_code', 'site.ended')->exists());

        $this->postJson($this->url('/closure'))->assertJsonValidationErrors(['site']);
        $this->postJson($this->url('/archive'))->assertOk()->assertJsonPath('data.can.archive', false);
        $this->postJson($this->url('/archive'))->assertJsonValidationErrors(['site']);

        $this->getJson('/api/v1/admin/sites')->assertJsonPath('meta.total', 0)->assertJsonPath('meta.counts.archived', 1);
        $this->getJson('/api/v1/admin/sites?archived=1')->assertJsonPath('meta.total', 1);
        $this->getJson($this->url())->assertOk();
    }

    public function test_the_team_tab_shows_the_members_and_the_attendance(): void
    {
        $this->confirmSite();
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertCreated();

        $this->asAdmin()->getJson($this->url('/team'))->assertOk()
            ->assertJsonCount(3, 'data.members')
            ->assertJsonPath('data.members.0.trade.label', 'Maçon')
            ->assertJsonPath('data.today.stats.total', 3)
            ->assertJsonPath('data.today.stats.present', 1)
            ->assertJsonPath('data.days', []);
    }

    public function test_an_incident_is_declared_taken_over_and_resolved(): void
    {
        $this->confirmSite();
        $promoter = $this->promoter;
        $form = ['incident_type' => 'securite', 'severity' => 'grave', 'occurred_at' => now()->subHour()->toIso8601String(), 'description' => 'Chute d’échafaudage.'];

        $this->asAdmin()->postJson($this->url('/incidents'), [...$form, 'description' => ''])->assertJsonValidationErrors(['description']);
        $this->postJson($this->url('/incidents'), $form)->assertCreated();
        $this->postJson($this->url('/incidents'), [...$form, 'severity' => 'mineure'])->assertCreated();

        $this->assertSame(1, Notification::query()->where('event_code', 'site.incident')->where('recipient_id', $promoter->id)->count());
        $list = $this->getJson($this->url('/incidents'))->assertOk()->assertJsonPath('meta.types.0.value', 'accident')->assertJsonPath('meta.severities.2.value', 'grave');
        $this->assertMatchesRegularExpression('/^INC-\d{4}-\d{5}$/', $list->json('data.0.reference'));
        $this->getJson('/api/v1/admin/sites')->assertJsonPath('data.0.open_incidents_count', 2)->assertJsonPath('meta.counts.todo.incidents', 1);

        $id = collect($list->json('data'))->firstWhere('severity.value', 'grave')['id'];
        $this->postJson($this->url("/incidents/{$id}/start"))->assertOk();
        $this->postJson($this->url("/incidents/{$id}/start"))->assertJsonValidationErrors(['site']);
        $this->getJson('/api/v1/admin/sites')->assertJsonPath('data.0.open_incidents_count', 2);

        $this->postJson($this->url("/incidents/{$id}/resolve"), ['resolution' => 'Échafaudage remplacé.'])->assertOk();
        $this->postJson($this->url("/incidents/{$id}/resolve"), ['resolution' => 'Une seconde fois.'])->assertJsonValidationErrors(['site']);
        $this->getJson('/api/v1/admin/sites')->assertJsonPath('data.0.open_incidents_count', 1);
        $this->assertTrue(Notification::query()->where('event_code', 'site.incident_resolved')->where('recipient_id', $promoter->id)->exists());
    }

    public function test_the_documents_and_the_history_of_a_site(): void
    {
        $this->confirmSite();

        $this->asAdmin()->getJson($this->url('/documents'))->assertOk();
        $this->postJson($this->url('/suspension'), ['reason' => 'Arrêt de sécurité.'])->assertOk();

        $response = $this->getJson($this->url('/history'))->assertOk()
            ->assertJsonPath('data.0.origin', 'admin')
            ->assertJsonPath('data.0.what', 'a suspendu le chantier')
            ->assertJsonPath('data.0.detail', 'Arrêt de sécurité.');
        $this->assertGreaterThan(1, $response->json('meta.total'));
        $this->assertSame(1, collect($response->json('data'))->where('what', 'a suspendu le chantier')->count());
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $this->confirmSite();
        $support = Admin::factory()->role('support')->create();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/sites')->assertForbidden();
        $this->asAdmin($support)->getJson('/api/v1/admin/sites')->assertOk();
        $this->postJson($this->url('/suspension'), ['reason' => 'Non autorisé.'])->assertForbidden();
        $this->postJson($this->url('/closure'))->assertForbidden();
        $this->get('/api/v1/admin/sites/export')->assertForbidden();
        $this->getJson($this->url('/team'))->assertOk();
        $this->postJson($this->url('/incidents'), [])->assertForbidden();
    }
}
