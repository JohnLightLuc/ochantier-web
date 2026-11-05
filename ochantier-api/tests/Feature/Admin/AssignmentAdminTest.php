<?php

namespace Tests\Feature\Admin;

use App\Enums\ReplacementStatus;
use App\Enums\UnavailabilityRequestStatus;
use App\Models\Admin;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\MatchingExclusion;
use App\Models\MatchingWeightSet;
use App\Models\Notification;
use App\Models\Replacement;
use App\Models\Suggestion;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\SiteReplacements;
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

    public function test_the_full_replacement_goes_from_search_to_promoter_choice(): void
    {
        $this->confirmSite();
        $weights = MatchingWeightSet::query()->where('is_active', true)->firstOrFail();
        $weights->forceFill(['exclusion_rules' => [...$weights->exclusion_rules, 'identite_verifiee' => false]])->save();
        $original = $this->assignmentOf('Kouamé Koffi');
        $url = "/api/v1/admin/assignments/{$original->id}";
        $free = $this->worker('Issa Traoré');
        $costly = $this->worker('Moussa Diallo', 'macon', (int) $original->promoter_daily_rate);

        $this->asAdmin()->postJson("{$url}/unavailability", ['reason' => 'indisponibilite'])->assertOk()->assertJsonPath('data.search.can_run', true)->assertJsonPath('data.search.can_send', false);
        $replacement = Replacement::query()->sole();

        $this->postJson("/api/v1/admin/assignments/replacements/{$replacement->id}/send")->assertJsonValidationErrors(['replacement']);

        $search = $this->postJson("/api/v1/admin/assignments/replacements/{$replacement->id}/search")->assertOk()->json('data.search');
        $rows = collect($search['suggestions']);
        $row = $rows->firstWhere('worker.id', $free->id);
        $this->assertNotNull($row);
        $this->assertSame((int) $original->promoter_daily_rate, $row['promoter_daily_rate']);
        $this->assertNull($rows->firstWhere('worker.id', $costly->id));
        $this->assertNull($rows->firstWhere('worker.id', $original->worker_id));
        $this->assertTrue(MatchingExclusion::query()->where('worker_id', $costly->id)->where('criterion', 'cout')->exists());

        $this->postJson("/api/v1/admin/matching/suggestions/{$row['id']}/contact", ['channel' => 'appel'])->assertOk();
        $this->postJson("/api/v1/admin/matching/suggestions/{$row['id']}/response", ['channel' => 'appel', 'decision' => 'accepte'])->assertOk();
        $this->getJson($url)->assertJsonPath('data.search.can_send', true);

        $this->postJson("/api/v1/admin/assignments/replacements/{$replacement->id}/search")->assertOk();
        $this->assertSame('accepte', collect($this->getJson($url)->json('data.search.suggestions'))->firstWhere('worker.id', $free->id)['status']['value']);

        $this->postJson("/api/v1/admin/assignments/replacements/{$replacement->id}/send")->assertOk()
            ->assertJsonPath('data.search.status.value', 'propose')
            ->assertJsonPath('data.search.can_run', false);
        $this->assertSame(ReplacementStatus::Propose, $replacement->fresh()->status);
        $this->assertSame(UnavailabilityRequestStatus::RemplacantPropose, $replacement->unavailabilityRequest->fresh()->status);
        $this->assertTrue(Suggestion::query()->where('worker_id', $costly->id)->doesntExist());
        $this->assertCount(1, app(SiteReplacements::class)->candidates($replacement->fresh()));
        $this->assertTrue(AuditLog::query()->where('action', 'replacement.sent')->exists());
        $this->assertTrue(Notification::query()->where('event_code', 'replacement.proposed')->exists());

        $this->actingAsPromoter($this->site->promoterProfile->user);
        $chosen = app(SiteReplacements::class)->candidates($replacement->fresh())->sole();
        $accept = "/api/v1/promoter/replacements/{$replacement->id}/accept";

        $clash = $original->replicate(['period']);
        $clash->forceFill(['worker_id' => $free->id])->save();
        $this->postJson($accept, ['suggestion_id' => $chosen->id])->assertUnprocessable()->assertJsonValidationErrors(['suggestion_id']);
        $clash->delete();

        $this->postJson($accept, ['suggestion_id' => $chosen->id])->assertOk();
        $this->assertSame(ReplacementStatus::Valide, $replacement->fresh()->status);
        $this->assertSame($free->id, $replacement->fresh()->replacementAssignment->worker_id);
        $this->assertSame((int) $original->promoter_daily_rate, $replacement->fresh()->replacementAssignment->promoter_daily_rate);
    }

    public function test_replacement_runs_stay_out_of_the_matching_page(): void
    {
        $this->confirmSite();
        $weights = MatchingWeightSet::query()->where('is_active', true)->firstOrFail();
        $weights->forceFill(['exclusion_rules' => [...$weights->exclusion_rules, 'identite_verifiee' => false]])->save();
        $original = $this->assignmentOf('Kouamé Koffi');
        $this->worker('Issa Traoré');
        $this->asAdmin()->postJson("/api/v1/admin/assignments/{$original->id}/unavailability", ['reason' => 'indisponibilite'])->assertOk();
        $this->postJson('/api/v1/admin/assignments/replacements/'.Replacement::query()->sole()->id.'/search')->assertOk();
        $this->assertTrue(Suggestion::query()->where('status', 'suggere')->exists());

        $response = $this->getJson('/api/v1/admin/matching/offers/'.$original->need->offer_id)->assertOk();
        $rows = collect($response->json('data.needs'))->flatMap(fn (array $need) => $need['suggestions']);
        $this->assertFalse($rows->contains(fn (array $row) => $row['status']['value'] === 'suggere'));
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
