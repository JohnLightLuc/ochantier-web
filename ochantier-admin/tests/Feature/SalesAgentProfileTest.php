<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\InvitationStatus;
use App\Enums\SalesAgentStatus;
use App\Models\SalesAgent;
use App\Models\User;
use Database\Seeders\SalesAgentSeeder;
use Database\Seeders\WorkerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesAgentProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_every_tab_of_the_profile_renders(): void
    {
        $this->seed([WorkerSeeder::class, SalesAgentSeeder::class]);

        foreach (['', '/profil-documents', '/ouvriers', '/activite', '/zones-disponibilites', '/objectifs-performances', '/classement', '/gains-paiements', '/acces-securite', '/historique'] as $tab) {
            $this->get("/commerciaux/mariam-kone{$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('SalesAgents/Show')->where('tab', ltrim($tab, '/')));
        }

        $this->get('/commerciaux/mariam-kone')->assertInertia(fn (Assert $page) => $page
            ->where('agent.code', 'COM-015')
            ->where('current.rank', 6)
            ->has('history', 6)
            ->has('documents', 6)
            ->has('objectives', 5)
            ->has('fieldActivities', 12)
            ->where('tabCounts.ouvriers', 3)
        );
        $this->get('/commerciaux/mariam-kone/inconnu')->assertNotFound();
    }

    public function test_pre_activation_profile_shows_invitation_banner(): void
    {
        $agent = SalesAgent::factory()->invited()->withDocuments(DocumentStatus::Missing)->create();

        $this->get("/commerciaux/{$agent->slug}")->assertInertia(fn (Assert $page) => $page
            ->where('banner.action', 'email')
            ->where('current', null)
            ->has('alerts', 7)
        );
    }

    public function test_agent_is_created_with_an_invitation_and_documents(): void
    {
        Storage::fake();

        $response = $this->post('/commerciaux', [
            ...$this->payload(),
            'id_front' => UploadedFile::fake()->create('recto.pdf', 100, 'application/pdf'),
        ]);

        $agent = SalesAgent::where('email', 'awa.kone@ochantier.ci')->firstOrFail();
        $response->assertRedirect("/commerciaux/{$agent->slug}/acces-securite");
        $this->assertSame('COM-001', $agent->code);
        $this->assertSame(SalesAgentStatus::Invited, $agent->status);
        $this->assertSame(InvitationStatus::Sent, $agent->invitation_status);
        $this->assertNull($agent->agency);
        $this->assertSame('Direction commerciale', $agent->supervisor);
        $this->assertSame(['Cocody', 'Plateau'], $agent->communes);
        $this->assertSame(1500, $agent->pay['per_registered']);
        $this->assertFalse($agent->access['permissions']['export']);
        $this->assertNotNull($agent->access['expires_at']);
        $this->assertCount(6, $agent->documents);
        $this->assertSame(DocumentStatus::ToReview, $agent->documents()->where('type', 'id_front')->first()->status);
        $this->assertSame(2, $agent->activities()->count());
    }

    public function test_phone_and_email_must_be_unique(): void
    {
        $existing = SalesAgent::factory()->create(['phone' => '+225 07 11 22 33 44', 'email' => 'awa.kone@ochantier.ci']);

        $this->post('/commerciaux', $this->payload())->assertSessionHasErrors(['phone', 'email']);

        $this->post("/commerciaux/{$existing->slug}", [...$this->payload(), 'monthly_target' => '30'])->assertRedirect("/commerciaux/{$existing->slug}");
        $this->assertSame(30, $existing->fresh()->monthly_target);
    }

    public function test_archived_agent_cannot_be_edited(): void
    {
        $agent = SalesAgent::factory()->create(['status' => SalesAgentStatus::Archived]);

        $this->get("/commerciaux/{$agent->slug}/modifier")->assertForbidden();
        $this->get('/commerciaux/nouveau')->assertInertia(fn (Assert $page) => $page->component('SalesAgents/Form')->where('code', sprintf('COM-%03d', (int) substr($agent->code, 4) + 1)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'first_name' => 'Awa',
            'last_name' => 'Koné',
            'phone' => '+225 07 11 22 33 44',
            'email' => 'Awa.Kone@ochantier.ci',
            'type' => 'Commercial indépendant',
            'agency' => 'Agence Cocody',
            'zone' => 'Cocody',
            'secondary_zones' => ['Plateau', 'Cocody'],
            'weekly_target' => '5',
            'monthly_target' => '20',
            'specialties' => ['Maçon'],
            'role' => 'Commercial terrain',
            'level' => 'Standard',
            'modules' => ['Ouvriers'],
            'permissions' => ['create' => '1', 'export' => '0'],
            'two_factor' => '1',
            'duration' => 'Illimitée',
            'pay_mode' => 'Commission',
            'per_registered' => '1 500',
            'per_validated' => '3500',
            'calc_frequency' => 'Hebdomadaire',
            'pay_frequency' => 'Mensuelle',
        ];
    }
}
