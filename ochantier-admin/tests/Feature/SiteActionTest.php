<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\SiteIncidentStatus;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'N. Yao']));
    }

    public function test_exceptional_creation_is_planned_and_audited(): void
    {
        Site::factory()->create(['reference' => 'CH-2026-0115']);

        $this->post('/chantiers', [
            'name' => 'Pont de Songon', 'promoter' => 'SICOGI', 'type' => 'Infrastructure publique', 'commune' => 'Songon',
            'required_workers' => '1 2', 'starts_on' => '2026-11-02', 'ends_on' => '2027-03-26', 'justification' => 'Chantier transmis par courrier officiel.',
        ])->assertRedirect('/chantiers/pont-de-songon');

        $site = Site::where('slug', 'pont-de-songon')->firstOrFail();
        $this->assertSame('CH-2026-0116', $site->reference);
        $this->assertSame(SiteStatus::Planned, $site->status);
        $this->assertSame(12, $site->required_workers);
        $this->assertSame(123, $site->planned_days);
        $this->assertSame(6, $site->documents()->where('status', DocumentStatus::Missing)->count());
        $this->assertDatabaseHas('site_activities', ['site_id' => $site->id, 'category' => 'creation', 'comment' => 'Chantier transmis par courrier officiel.']);
    }

    public function test_creation_validates_dates_and_justification(): void
    {
        $this->post('/chantiers', ['name' => 'X', 'promoter' => 'SICOGI', 'type' => 'Usine', 'commune' => 'Paris', 'required_workers' => '0', 'starts_on' => '2026-11-02', 'ends_on' => '2026-10-01', 'justification' => 'court'])
            ->assertSessionHasErrors(['commune', 'required_workers', 'ends_on', 'justification']);
        $this->post('/chantiers', ['name' => 'Y'])->assertSessionHasErrors(['starts_on' => 'La date de début est obligatoire.', 'ends_on' => 'La date de fin est obligatoire.']);
        $this->assertSame(0, Site::count());
    }

    public function test_authorized_information_update_and_read_only_sites(): void
    {
        $site = Site::factory()->create();

        $this->put("/chantiers/{$site->slug}/informations", ['name' => 'Résidence Renommée', 'priority' => 'high', 'working_hours' => '07:00 – 16:00', 'instructions' => '', 'access_conditions' => '', 'safety_equipment' => 'Casque'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Résidence Renommée', $site->fresh()->name);
        $this->assertDatabaseHas('site_activities', ['site_id' => $site->id, 'action' => 'a modifié les informations autorisées']);

        $cancelled = Site::factory()->status(SiteStatus::Cancelled)->create();
        $this->put("/chantiers/{$cancelled->slug}/informations", ['name' => 'Autre', 'priority' => 'high'])->assertForbidden();
    }

    public function test_correction_request_is_sent_to_the_promoter(): void
    {
        $site = Site::factory()->create();

        $this->post("/chantiers/{$site->slug}/correction", ['field' => 'Dates', 'message' => 'court'])->assertSessionHasErrors('message');
        $this->post("/chantiers/{$site->slug}/correction", ['field' => 'Dates', 'message' => 'La date de fin doit être reportée.'])->assertSessionHasNoErrors();
        $this->assertSame('Dates — La date de fin doit être reportée.', $site->fresh()->correction_request);
    }

    public function test_planning_a_draft_site(): void
    {
        $site = Site::factory()->draft()->create();

        $this->post("/chantiers/{$site->slug}/planification", ['starts_on' => '2026-11-02', 'ends_on' => '2027-03-26'])->assertSessionHasNoErrors();
        $site->refresh();
        $this->assertSame(SiteStatus::Planned, $site->status);
        $this->assertSame('2026-11-02', $site->starts_on->format('Y-m-d'));
        $this->assertSame(123, $site->planned_days);

        $running = Site::factory()->create();
        $this->post("/chantiers/{$running->slug}/planification", ['starts_on' => '2026-11-02', 'ends_on' => '2027-03-26'])->assertStatus(422);
    }

    public function test_suspension_and_resumption(): void
    {
        $site = Site::factory()->create();

        $this->post("/chantiers/{$site->slug}/suspension", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/chantiers/{$site->slug}/suspension", ['reason' => 'Permis à régulariser'])->assertSessionHasNoErrors();
        $this->assertSame(SiteStatus::Suspended, $site->fresh()->status);
        $this->assertDatabaseHas('site_activities', ['site_id' => $site->id, 'action' => 'a suspendu le chantier', 'old_value' => 'En cours', 'new_value' => 'Suspendu', 'comment' => 'Permis à régulariser']);

        $this->post("/chantiers/{$site->slug}/suspension", ['reason' => 'Encore une fois'])->assertStatus(422);
        $this->post("/chantiers/{$site->slug}/reprise")->assertSessionHasNoErrors();
        $this->assertSame(SiteStatus::InProgress, $site->fresh()->status);

        $finished = Site::factory()->status(SiteStatus::Suspended, 100)->create();
        $this->post("/chantiers/{$finished->slug}/reprise");
        $this->assertSame(SiteStatus::PendingClosure, $finished->fresh()->status);
    }

    public function test_closure_archiving_and_cancellation(): void
    {
        $site = Site::factory()->status(SiteStatus::PendingClosure, 96)->withDocuments(DocumentStatus::ToReview)->create();

        $this->post("/chantiers/{$site->slug}/archivage")->assertStatus(422);
        $this->post("/chantiers/{$site->slug}/cloture")->assertSessionHasNoErrors();
        $site->refresh();
        $this->assertSame(SiteStatus::Closed, $site->status);
        $this->assertSame(100, $site->progress);
        $this->assertDatabaseHas('site_activities', ['site_id' => $site->id, 'action' => 'a clôturé le chantier', 'comment' => 'PV de réception non vérifié']);

        $this->post("/chantiers/{$site->slug}/annulation", ['reason' => 'Projet abandonné'])->assertStatus(422);
        $this->post("/chantiers/{$site->slug}/archivage")->assertSessionHasNoErrors();
        $this->assertSame(SiteStatus::Archived, $site->fresh()->status);

        $other = Site::factory()->draft()->create();
        $this->post("/chantiers/{$other->slug}/annulation", ['reason' => 'Projet abandonné'])->assertSessionHasNoErrors();
        $this->assertSame(SiteStatus::Cancelled, $other->fresh()->status);
    }

    public function test_incidents_are_reported_and_resolved(): void
    {
        $site = Site::factory()->create();

        $this->post("/chantiers/{$site->slug}/incidents", ['type' => 'Accident', 'severity' => 'critical', 'description' => 'Chute d’un échafaudage, sans blessé.'])->assertSessionHasNoErrors();
        $incident = $site->incidents()->firstOrFail();
        $this->assertSame('INC-400', $incident->reference);
        $this->assertSame('N. Yao', $incident->reported_by);

        $other = Site::factory()->create();
        $this->post("/chantiers/{$other->slug}/incidents/{$incident->id}/resolution", ['measures' => 'Échafaudage remplacé.'])->assertNotFound();

        $this->post("/chantiers/{$site->slug}/incidents/{$incident->id}/resolution", ['measures' => 'Échafaudage remplacé.'])->assertSessionHasNoErrors();
        $this->assertSame(SiteIncidentStatus::Resolved, $incident->fresh()->status);
        $this->post("/chantiers/{$site->slug}/incidents/{$incident->id}/resolution", ['measures' => 'Encore'])->assertStatus(422);

        $draft = Site::factory()->draft()->create();
        $this->post("/chantiers/{$draft->slug}/incidents", ['type' => 'Accident', 'severity' => 'low', 'description' => 'Incident sur un brouillon.'])->assertStatus(422);
    }

    public function test_documents_are_uploaded_validated_and_rejected(): void
    {
        Storage::fake();
        $site = Site::factory()->status(SiteStatus::PendingClosure, 100)->withDocuments()->create();

        $this->post("/chantiers/{$site->slug}/documents", ['type' => 'acceptance_report', 'reference' => 'PV-2026-77', 'file' => UploadedFile::fake()->create('pv.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();
        $document = $site->documents()->where('type', 'acceptance_report')->firstOrFail();
        $this->assertSame(DocumentStatus::ToReview, $document->status);
        Storage::assertExists($document->file_path);

        $this->post("/chantiers/{$site->slug}/documents/{$document->id}/validation")->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Verified, $document->fresh()->status);

        $this->post("/chantiers/{$site->slug}/documents/{$document->id}/rejet", ['reason' => 'Document illisible', 'comment' => 'Signature du maître d’ouvrage absente.'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Rejected, $document->fresh()->status);
        $this->post("/chantiers/{$site->slug}/documents/{$document->id}/validation")->assertStatus(422);
    }
}
