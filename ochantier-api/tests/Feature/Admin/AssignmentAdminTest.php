<?php

namespace Tests\Feature\Admin;

use App\Enums\ReplacementStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Replacement;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Affectations : liste, export, fiche et déclaration d’indisponibilité.
 */
class AssignmentAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    public function test_the_list_shows_one_line_per_worker(): void
    {
        $this->confirmSite();

        $response = $this->asAdmin()->getJson('/api/v1/admin/assignments')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.counts.statuses.confirmee', 3)
            ->assertJsonPath('data.0.site.label', 'Résidence Les Palmiers')
            ->assertJsonPath('data.0.replacement', null);
        $this->assertNotNull($response->json('data.0.worker.phone'));
        $this->assertNotEmpty($response->json('meta.reasons'));

        foreach (['status=confirmee', 'search=Kouamé', 'search=Palmiers', 'todo=remplacements', 'todo=demarrage', 'todo=fin', 'sort=worker', 'sort=end&direction=desc', 'sort=status', 'site_id='.$this->site->id] as $query) {
            $this->getJson('/api/v1/admin/assignments?'.$query)->assertOk();
        }

        $this->getJson('/api/v1/admin/assignments?search=Kouamé')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/assignments?todo=demarrage')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/admin/assignments?status=inconnu')->assertJsonValidationErrors(['status']);

        $csv = $this->get('/api/v1/admin/assignments/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Kouamé Koffi', $csv);
        $this->assertTrue(AuditLog::query()->where('action', 'assignments.exported')->exists());
    }

    public function test_an_operator_can_declare_an_unavailability(): void
    {
        $this->confirmSite();
        $assignment = $this->assignmentOf('Kouamé Koffi');
        $url = "/api/v1/admin/assignments/{$assignment->id}";

        $this->asAdmin()->getJson($url)->assertOk()
            ->assertJsonPath('data.days.validated', 0)
            ->assertJsonPath('data.can.declare_unavailability', true);

        $this->postJson("{$url}/unavailability", ['reason' => 'inconnu'])->assertJsonValidationErrors(['reason']);

        $this->postJson("{$url}/unavailability", ['reason' => 'indisponibilite', 'comment' => 'Hospitalisé hier soir.'])->assertOk()
            ->assertJsonPath('data.replacement.status.value', 'recherche')
            ->assertJsonPath('data.can.declare_unavailability', false)
            ->assertJsonPath('data.unavailabilities.0.declared_by.value', 'admin')
            ->assertJsonPath('data.unavailabilities.0.comment', 'Hospitalisé hier soir.');

        $this->assertSame(ReplacementStatus::Recherche, Replacement::query()->sole()->status);
        $this->assertNull(Replacement::query()->sole()->requested_by_user_id);
        $this->assertTrue(AuditLog::query()->where('action', 'assignment.unavailability_declared')->exists());
        $this->postJson("{$url}/unavailability", ['reason' => 'indisponibilite'])->assertUnprocessable()->assertJsonValidationErrors(['assignment']);

        $this->getJson('/api/v1/admin/assignments?todo=remplacements')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $assignment->id);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $this->confirmSite();
        $assignment = Assignment::query()->firstOrFail();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/assignments')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('finance')->create())->getJson('/api/v1/admin/assignments')->assertOk();
        $this->postJson("/api/v1/admin/assignments/{$assignment->id}/unavailability", ['reason' => 'indisponibilite'])->assertForbidden();
    }
}
