<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\WorkerStatus;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkerDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_validating_a_document_moves_the_profile_into_review(): void
    {
        $worker = Worker::factory()->toReview()->withDocuments(DocumentStatus::ToReview)->create();
        $document = $worker->documents()->first();

        $this->post("/ouvriers/{$worker->slug}/documents/{$document->id}/validation")->assertRedirect();

        $this->assertSame(DocumentStatus::Verified, $document->fresh()->status);
        $this->assertSame(WorkerStatus::InReview, $worker->fresh()->status);
        $this->post("/ouvriers/{$worker->slug}/documents/{$document->id}/validation")->assertStatus(422);
    }

    public function test_document_can_be_rejected_commented_and_replaced(): void
    {
        Storage::fake();
        $worker = Worker::factory()->withDocuments(DocumentStatus::ToReview)->create();
        $document = $worker->documents()->first();

        $this->post("/ouvriers/{$worker->slug}/documents/{$document->id}/rejet", ['reason' => 'Document illisible', 'request' => 'Déposer une nouvelle photo nette', 'comment' => 'Photo floue, numéro illisible.'])->assertRedirect();
        $this->assertSame(DocumentStatus::Rejected, $document->fresh()->status);

        $this->post("/ouvriers/{$worker->slug}/documents/{$document->id}/commentaire", ['comment' => 'Rappeler l’ouvrier'])->assertRedirect();
        $this->assertSame('Rappeler l’ouvrier', $document->fresh()->internal_comment);

        $this->post("/ouvriers/{$worker->slug}/documents", ['type' => $document->type->value, 'reference' => 'CI0001', 'file' => UploadedFile::fake()->image('recto.jpg')])->assertRedirect();
        $document->refresh();
        $this->assertSame(DocumentStatus::ToReview, $document->status);
        $this->assertNull($document->rejection_reason);

        $this->get("/ouvriers/{$worker->slug}/documents/{$document->id}/telechargement")->assertOk()->assertDownload('recto.jpg');
    }

    public function test_documents_are_scoped_to_their_worker(): void
    {
        $worker = Worker::factory()->withDocuments(DocumentStatus::ToReview)->create();
        $other = Worker::factory()->withDocuments(DocumentStatus::ToReview)->create();

        $this->post("/ouvriers/{$worker->slug}/documents/{$other->documents()->first()->id}/validation")->assertNotFound();
    }
}
