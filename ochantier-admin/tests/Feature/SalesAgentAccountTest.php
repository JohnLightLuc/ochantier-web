<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentStatus;
use App\Models\SalesAgent;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAgentAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_invitation_flow_from_draft_to_activation(): void
    {
        $agent = SalesAgent::factory()->draft()->create();
        $base = "/commerciaux/{$agent->slug}";

        $this->post("{$base}/lien/copie")->assertStatus(422);
        $this->post("{$base}/invitation")->assertRedirect();
        $agent->refresh();
        $this->assertSame(SalesAgentStatus::Invited, $agent->status);
        $this->assertSame(InvitationStatus::Sent, $agent->invitation_status);
        $this->assertSame(today()->addDays(3)->format('Y-m-d'), $agent->access['expires_at']);

        $this->post("{$base}/lien/revocation")->assertRedirect();
        $this->assertSame(InvitationStatus::Revoked, $agent->fresh()->invitation_status);
        $this->post("{$base}/lien/copie")->assertStatus(422);
        $this->post("{$base}/lien/nouveau")->assertRedirect();

        SalesAgent::factory()->create(['email' => 'pris@ochantier.ci']);
        $this->post("{$base}/email", ['email' => 'PRIS@ochantier.ci'])->assertSessionHasErrors('email');
        $this->post("{$base}/email", ['email' => 'Nouvelle.Adresse@ochantier.ci'])->assertRedirect();
        $this->assertSame('nouvelle.adresse@ochantier.ci', $agent->fresh()->email);

        $this->post("{$base}/activation")->assertRedirect();
        $agent->refresh();
        $this->assertSame(SalesAgentStatus::Active, $agent->status);
        $this->assertSame(InvitationStatus::Activated, $agent->invitation_status);
        $this->assertNull($agent->access['expires_at']);
        $this->post("{$base}/invitation")->assertStatus(422);
        $this->post("{$base}/email", ['email' => 'autre@ochantier.ci'])->assertStatus(422);
    }

    public function test_suspension_excludes_from_ranking_and_reactivation_restores(): void
    {
        $agent = SalesAgent::factory()->create(['availability' => SalesAgentAvailability::FieldMission]);
        $base = "/commerciaux/{$agent->slug}";

        $this->post("{$base}/suspension", ['category' => 'Autre', 'reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post("{$base}/suspension", ['category' => 'Inactivité prolongée', 'reason' => 'Aucune activité depuis un mois.'])->assertRedirect();
        $agent->refresh();
        $this->assertSame(SalesAgentStatus::Suspended, $agent->status);
        $this->assertSame(SalesAgentAvailability::Unavailable, $agent->availability);
        $this->assertSame('2026-08', $agent->ranking_until);
        $this->assertSame([], $agent->access['sessions']);

        $this->post("{$base}/reactivation")->assertRedirect();
        $agent->refresh();
        $this->assertSame(SalesAgentStatus::Active, $agent->status);
        $this->assertSame(SalesAgentAvailability::FieldMission, $agent->availability);
        $this->assertNull($agent->ranking_until);
    }

    public function test_deactivation_archive_and_deletion(): void
    {
        $agent = SalesAgent::factory()->create();
        $worker = Worker::factory()->create(['recruiter' => $agent->name]);
        $base = "/commerciaux/{$agent->slug}";

        $this->post("{$base}/archivage")->assertStatus(422);
        $this->post("{$base}/desactivation", ['category' => 'Fin de contrat', 'reason' => 'Contrat non renouvelé.'])->assertRedirect();
        $this->post("{$base}/archivage")->assertRedirect();
        $this->assertSame(SalesAgentStatus::Archived, $agent->fresh()->status);

        $this->delete($base, ['category' => 'Doublon', 'comment' => 'Compte créé en double.', 'confirm' => 'COM-000'])->assertSessionHasErrors('confirm');
        $this->delete($base, ['category' => 'Doublon', 'comment' => 'Compte créé en double.', 'confirm' => strtolower($agent->code)])->assertRedirect('/commerciaux');
        $this->assertModelMissing($agent);
        $this->assertModelExists($worker);
    }

    public function test_permissions_role_two_factor_and_sessions(): void
    {
        $agent = SalesAgent::factory()->create();
        $base = "/commerciaux/{$agent->slug}";

        $this->post("{$base}/permissions/export")->assertRedirect();
        $this->assertFalse($agent->fresh()->access['permissions']['export']);
        $this->post("{$base}/permissions/inconnue")->assertNotFound();

        $this->post("{$base}/role", ['role' => 'Superviseur', 'level' => 'Complet', 'duration' => '12 mois'])->assertRedirect();
        $this->assertSame('Complet', $agent->fresh()->access['level']);

        $this->post("{$base}/double-authentification")->assertRedirect();
        $this->assertFalse($agent->fresh()->access['two_factor']);

        $this->delete("{$base}/sessions/inconnue")->assertNotFound();
        $this->delete("{$base}/sessions/s1")->assertRedirect();
        $this->assertSame([], $agent->fresh()->access['sessions']);

        $this->post("{$base}/reinitialisation")->assertRedirect();
        $this->assertSame(5, $agent->activities()->count());
    }
}
