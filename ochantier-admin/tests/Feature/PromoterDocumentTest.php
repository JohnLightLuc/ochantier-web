<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Promoter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PromoterDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Promoter $promoter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->promoter = Promoter::factory()->withDocuments(DocumentStatus::ToReview)->create();
    }

    public function test_document_can_be_uploaded_and_downloaded(): void
    {
        Storage::fake();
        $this->promoter->documents()->where('type', DocumentType::TaxCertificate)->update(['status' => DocumentStatus::Missing]);

        $this->post("/promoteurs/{$this->promoter->slug}/documents", [
            'type' => 'tax_certificate',
            'reference' => 'AF-2026-1',
            'expires_at' => '2027-12-31',
            'file' => UploadedFile::fake()->create('attestation.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $document = $this->promoter->documents()->where('type', DocumentType::TaxCertificate)->first();
        $this->assertSame(DocumentStatus::ToReview, $document->status);
        $this->assertSame('AF-2026-1', $document->reference);
        Storage::assertExists($document->file_path);

        $this->get("/promoteurs/{$this->promoter->slug}/documents/{$document->id}/telechargement")
            ->assertOk()
            ->assertDownload('attestation-fiscale-'.$this->promoter->slug.'.pdf');
    }

    public function test_upload_requires_reference_and_file(): void
    {
        $this->post("/promoteurs/{$this->promoter->slug}/documents", ['type' => 'tax_certificate'])
            ->assertSessionHasErrors(['reference' => 'La référence est obligatoire.', 'file' => 'Ajoutez un fichier (PDF, JPG ou PNG).']);
    }

    public function test_document_to_review_can_be_validated_once(): void
    {
        $document = $this->promoter->documents()->first();

        $this->post("/promoteurs/{$this->promoter->slug}/documents/{$document->id}/validation")->assertRedirect();
        $this->assertSame(DocumentStatus::Verified, $document->fresh()->status);

        $this->post("/promoteurs/{$this->promoter->slug}/documents/{$document->id}/validation")->assertStatus(422);
    }

    public function test_document_can_be_rejected_with_a_reason(): void
    {
        $document = $this->promoter->documents()->first();

        $this->post("/promoteurs/{$this->promoter->slug}/documents/{$document->id}/rejet", ['category' => 'Document illisible', 'reason' => 'Le scan est flou.'])->assertRedirect();

        $document->refresh();
        $this->assertSame(DocumentStatus::Rejected, $document->status);
        $this->assertSame('Document illisible — Le scan est flou.', $document->rejection_reason);
    }

    public function test_documents_are_scoped_to_their_promoter(): void
    {
        $other = Promoter::factory()->withDocuments(DocumentStatus::ToReview)->create();
        $foreign = $other->documents()->first();

        $this->post("/promoteurs/{$this->promoter->slug}/documents/{$foreign->id}/validation")->assertNotFound();
    }

    public function test_seeded_document_without_file_downloads_a_text_summary(): void
    {
        $document = $this->promoter->documents()->first();

        $this->get("/promoteurs/{$this->promoter->slug}/documents/{$document->id}/telechargement")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }
}
