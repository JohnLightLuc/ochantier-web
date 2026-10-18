<?php

namespace Tests\Feature\Commercial;

use App\Enums\DocumentVerificationStatus;
use App\Enums\ProspectStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Consent;
use App\Models\Document;
use App\Models\Prospect;
use App\Models\User;
use App\Models\VerificationReview;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;

class ProspectTest extends CommercialTestCase
{
    public function test_a_new_contact_is_saved_without_starting_a_dossier(): void
    {
        $response = $this->postJson('/api/v1/commercial/prospects', $this->contact())
            ->assertCreated()
            ->assertJsonPath('message', 'Prospect enregistré')
            ->assertJsonPath('data.full_name', 'Jean Kouassi')
            ->assertJsonPath('data.phone', '+225 07 12 34 56 78')
            ->assertJsonPath('data.commune.name', 'Cocody')
            ->assertJsonPath('data.status.value', 'nouveau')
            ->assertJsonPath('data.worker', null)
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.hint', 'Nouveau contact · dossier à commencer');

        $this->assertSame(35, $response->json('data.completion.percent'));
        $this->assertSame(0, Worker::query()->count());
        $this->assertTrue(Consent::query()->where('subject_type', 'prospect')->where('consent_type', 'contact')->where('channel', 'terrain')->exists());
    }

    public function test_the_contact_consent_and_the_required_fields_are_checked(): void
    {
        $this->postJson('/api/v1/commercial/prospects', ['phone' => '0712'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact_consent.0', 'Cochez l’accord de contact pour continuer.')
            ->assertJsonPath('errors.phone.0', 'Saisissez un numéro à 10 chiffres.')
            ->assertJsonPath('errors.commune_id.0', 'Sélectionnez une commune.')
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_a_phone_already_followed_or_registered_is_refused(): void
    {
        $this->createProspect();

        $this->postJson('/api/v1/commercial/prospects', $this->contact(['first_name' => 'Autre']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Ce numéro existe déjà dans vos prospects.');

        $this->getJson('/api/v1/commercial/prospects/phone-check?phone=0712345678')
            ->assertOk()
            ->assertJsonPath('available', false);

        $this->getJson('/api/v1/commercial/prospects/phone-check?phone=0799999999')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('message', 'Aucun doublon trouvé');
    }

    public function test_the_same_offline_entry_is_never_created_twice(): void
    {
        $payload = $this->contact(['client_uuid' => '0b9a2c1e-4f3d-4b6a-9c8e-7d6f5e4a3b21']);

        $id = $this->postJson('/api/v1/commercial/prospects', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/commercial/prospects', $payload)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, Prospect::query()->count());
    }

    public function test_completing_the_dossier_creates_a_draft_worker(): void
    {
        $id = $this->createProspect();
        $maconnerie = $this->trade()->specialties()->firstOrFail();
        $skill = $this->trade()->skills()->firstOrFail();

        $this->patchJson("/api/v1/commercial/prospects/{$id}", $this->dossier([
            'specialty_ids' => [$maconnerie->id],
            'skill_ids' => [$skill->id],
            'secondary_trade_id' => $this->trade('carreleur')->id,
            'secondary_experience_years' => 2,
            'experiences' => ['Construction villa R+1 à Bingerville (2023)'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'a_completer')
            ->assertJsonPath('data.identity.gender.value', 'M')
            ->assertJsonPath('data.activity.daily_rate', 15000)
            ->assertJsonPath('data.activity.level.value', 'confirme')
            ->assertJsonPath('data.activity.secondary_trade.trade.code', 'carreleur')
            ->assertJsonPath('data.activity.specialties.0.label', $maconnerie->label)
            ->assertJsonPath('data.emergency_contact.phone', '+225 05 98 76 54 32')
            ->assertJsonPath('data.missing.0.key', 'identity');

        $worker = Worker::query()->sole();
        $this->assertMatchesRegularExpression('/^OUV-\d{6}$/', $worker->reference);
        $this->assertSame(WorkerVerificationStatus::Brouillon, $worker->verification_status);
        $this->assertSame(2, $worker->workerTrades()->count());
        $this->assertSame('+2250712345678', $worker->phone);
    }

    public function test_skills_of_another_trade_are_refused(): void
    {
        $id = $this->createProspect();

        $this->patchJson("/api/v1/commercial/prospects/{$id}", ['skill_ids' => [$this->trade('electricien')->skills()->value('id')]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['skill_ids.0' => ['Cette compétence ne correspond pas aux métiers choisis.']]);
    }

    public function test_identity_documents_and_photo_complete_the_dossier(): void
    {
        $id = $this->createProspect();
        $this->patchJson("/api/v1/commercial/prospects/{$id}", $this->dossier())->assertOk();

        $this->post("/api/v1/commercial/prospects/{$id}/documents", ['document_type' => 'worker_cni', 'recto' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number' => 'Indiquez le numéro du document.', 'verso' => 'Ajoutez le verso du document.']);

        $this->uploadIdentity($id);
        $this->uploadPhoto($id);

        $detail = $this->getJson("/api/v1/commercial/prospects/{$id}")
            ->assertOk()
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.stage.value', 'pret')
            ->assertJsonPath('data.hint', 'Prêt à soumettre')
            ->assertJsonPath('data.documents.0.key', 'identity')
            ->assertJsonPath('data.documents.0.state.value', 'fourni')
            ->assertJsonPath('data.documents.0.document.number_last4', '5678')
            ->assertJsonPath('data.documents.1.key', 'worker_photo')
            ->assertJsonPath('data.documents.1.state.value', 'fourni')
            ->assertJsonPath('data.documents.2.state.value', 'non_fourni');

        $this->assertSame(75, $detail->json('data.completion.percent'));
        $this->assertNotNull(Worker::query()->sole()->photo_media_id);

        $this->get($detail->json('data.documents.0.document.files.0.url'))->assertOk();
        $this->getJson('/api/v1/media/'.$detail->json('data.documents.0.document.files.0.id'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Ce lien a expiré. Rechargez la page pour en obtenir un nouveau.');
    }

    public function test_a_new_identity_document_replaces_the_previous_one(): void
    {
        $id = $this->createProspect();
        $this->uploadIdentity($id, 'CI0000000001');

        $this->post("/api/v1/commercial/prospects/{$id}/documents", [
            'document_type' => 'worker_passport',
            'number' => '20AB12345',
            'file' => UploadedFile::fake()->create('passeport.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('message', 'Document ajouté');

        $this->assertSame(DocumentVerificationStatus::Remplace, Document::query()->where('number_last4', '0001')->sole()->verification_status);
        $this->getJson("/api/v1/commercial/prospects/{$id}")->assertJsonPath('data.documents.0.document.type.code', 'worker_passport');
    }

    public function test_a_document_can_be_removed_before_submission(): void
    {
        $id = $this->createProspect();
        $this->uploadPhoto($id);
        $document = Document::query()->sole();

        $this->deleteJson("/api/v1/commercial/prospects/{$id}/documents/{$document->id}")->assertNoContent();

        $this->assertSoftDeleted($document);
        $this->assertNull(Worker::query()->sole()->photo_media_id);
    }

    public function test_an_incomplete_dossier_cannot_be_submitted(): void
    {
        $id = $this->createProspect();
        $this->patchJson("/api/v1/commercial/prospects/{$id}", $this->dossier())->assertOk();

        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")
            ->assertUnprocessable()
            ->assertJsonPath('errors.dossier.0', 'Dossier incomplet : pièce d’identité, photo d’identité.');
    }

    public function test_a_complete_dossier_is_sent_to_the_back_office(): void
    {
        $id = $this->readyProspect();

        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('message', 'Dossier transmis au Back office')
            ->assertJsonPath('worker.status.value', 'soumis');

        $worker = Worker::query()->sole();
        $this->assertSame(WorkerVerificationStatus::Soumis, $worker->verification_status);
        $this->assertSame($id, $worker->prospect_id);
        $this->assertSame(ProspectStatus::Converti, Prospect::query()->find($id)->status);
        $this->assertSame('soumis', $worker->statusHistories()->sole()->to_status->value);
        $review = VerificationReview::query()->sole();
        $this->assertSame('worker', $review->subject_type);
        $this->assertCount(2, $review->snapshot['documents']);

        $this->getJson('/api/v1/commercial/prospects')->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/commercial/prospects/{$id}", ['first_name' => 'Jean-Marc'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.prospect.0', 'Dossier transmis : la modification se fait via le Back office.');
    }

    public function test_a_change_made_on_another_device_is_detected(): void
    {
        $id = $this->createProspect();
        $version = $this->getJson("/api/v1/commercial/prospects/{$id}")->json('data.lock_version');

        $this->patchJson("/api/v1/commercial/prospects/{$id}", ['first_name' => 'Jean-Marc', 'lock_version' => $version])->assertOk();
        $this->patchJson("/api/v1/commercial/prospects/{$id}", ['first_name' => 'Jean-Paul', 'lock_version' => $version])
            ->assertConflict()
            ->assertJsonPath('message', 'Ce prospect a été modifié sur un autre appareil. Rechargez-le avant de continuer.');
    }

    public function test_an_archived_prospect_frees_its_phone_number(): void
    {
        $id = $this->createProspect();
        $this->patchJson("/api/v1/commercial/prospects/{$id}", $this->dossier())->assertOk();

        $this->postJson("/api/v1/commercial/prospects/{$id}/abandon", ['reason_code' => 'injoignable'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'abandonne');

        $this->assertSoftDeleted(Worker::withTrashed()->sole());
        $this->createProspect();
    }

    public function test_the_list_is_filtered_by_name_commune_and_progress(): void
    {
        $ready = $this->readyProspect();
        $this->createProspect(['first_name' => 'Aminata', 'last_name' => 'Traoré', 'phone' => '0544210987', 'commune_id' => $this->commune('Yopougon')]);

        $this->getJson('/api/v1/commercial/prospects')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/commercial/prospects?search=amin')->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Traoré');
        $this->getJson('/api/v1/commercial/prospects?commune_id='.$this->commune('Yopougon'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/commercial/prospects?progress=lt50')->assertJsonCount(1, 'data')->assertJsonPath('data.0.last_name', 'Traoré');
        $this->getJson('/api/v1/commercial/prospects?progress=50_99')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ready);
    }

    public function test_a_commercial_cannot_see_the_prospects_of_another(): void
    {
        $id = $this->createProspect();

        $this->actingAsCommercial(User::factory()->commercial()->create());

        $this->getJson("/api/v1/commercial/prospects/{$id}")->assertNotFound()->assertJsonPath('message', 'Élément introuvable.');
        $this->getJson('/api/v1/commercial/prospects/not-a-uuid')->assertNotFound();
        $this->getJson('/api/v1/commercial/prospects')->assertJsonCount(0, 'data');
    }

    public function test_the_commercial_space_requires_an_active_commercial(): void
    {
        $this->actingAsCommercial(User::factory()->promoter()->create());
        $this->getJson('/api/v1/commercial/prospects')->assertForbidden();

        $this->commercial->commercialProfile->update(['status' => 'termine', 'ended_at' => now()]);
        $this->actingAsCommercial($this->commercial);
        $this->getJson('/api/v1/commercial/prospects')
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre accès commercial n’est plus actif. Contactez votre responsable O’CHANTIER.');
    }

    public function test_the_form_referentials_are_available(): void
    {
        $this->getJson('/api/v1/referentials')
            ->assertOk()
            ->assertJsonPath('cities.0.name', 'Abidjan')
            ->assertJsonPath('trades.0.code', 'macon')
            ->assertJsonPath('worker_document_types.0.code', 'worker_cni')
            ->assertJsonPath('worker_document_types.0.has_back_side', true);
    }
}
