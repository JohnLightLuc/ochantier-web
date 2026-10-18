<?php

namespace Tests\Feature\Promoter;

use App\Enums\DocumentVerificationStatus;
use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Models\Commune;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationReview;
use Database\Seeders\HelpCenterSeeder;
use Illuminate\Http\UploadedFile;

class PromoterProfileTest extends PromoterTestCase
{
    public function test_a_new_account_lists_what_is_missing_for_the_verification(): void
    {
        $this->getJson('/api/v1/promoter/profile')
            ->assertOk()
            ->assertJsonPath('data.kind.value', 'particulier')
            ->assertJsonPath('data.phone', '+225 07 12 34 56 34')
            ->assertJsonPath('data.phone_masked', '+225 07 •• •• •• 34')
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.verification.title', 'Identité')
            ->assertJsonPath('data.verification.state.value', 'a_completer')
            ->assertJsonPath('data.verification.summary', 'À compléter · 3 éléments manquants')
            ->assertJsonPath('data.completion.percent', 0)
            ->assertJsonPath('data.completion.missing.*.key', ['name', 'identity', 'residence'])
            ->assertJsonPath('data.documents.title', 'Documents d’identité')
            ->assertJsonPath('data.documents.slots.0.accepted_types.*.code', ['promoter_cni', 'promoter_passport', 'promoter_driving_license'])
            ->assertJsonPath('data.documents.slots.1.hint', 'Daté de moins de 3 mois')
            ->assertJsonPath('data.documents.slots.1.state.value', 'manquant')
            ->assertJsonPath('data.stats', ['sites' => 0, 'active_sites' => 0, 'users' => 1]);
    }

    public function test_an_individual_is_sent_for_verification_once_everything_is_provided(): void
    {
        $cocody = Commune::query()->where('name', 'Cocody')->value('id');

        $this->patchJson('/api/v1/promoter/profile', ['first_name' => 'Nathanaël', 'last_name' => 'Yao', 'address' => 'Angré 8e tranche', 'commune_id' => $cocody])
            ->assertOk()
            ->assertJsonPath('message', 'Profil mis à jour')
            ->assertJsonPath('data.initials', 'NY')
            ->assertJsonPath('data.address.commune.name', 'Cocody')
            ->assertJsonPath('data.completion.percent', 33);

        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_cni', 'recto' => $this->image('recto.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.verso.0', 'Ajoutez le verso du document.');

        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_cni', 'recto' => $this->image('recto.jpg'), 'verso' => $this->image('verso.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message', 'Carte nationale d’identité ajouté')
            ->assertJsonPath('data.documents.slots.0.state.value', 'en_verification')
            ->assertJsonPath('data.verification.status.value', 'brouillon');

        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_residence_certificate', 'file' => $this->pdf('residence.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.completion.percent', 100)
            ->assertJsonPath('data.verification.status.value', 'soumis')
            ->assertJsonPath('data.verification.state.value', 'en_verification')
            ->assertJsonPath('data.verification.summary', 'Documents en cours de vérification')
            ->assertJsonPath('data.verification.can_change_kind', false);

        $review = VerificationReview::query()->sole();
        $this->assertSame('promoter_profile', $review->subject_type);
        $this->assertSame('Yao', $review->snapshot['user']['last_name']);
        $this->assertCount(2, $review->snapshot['documents']);
    }

    public function test_a_company_account_requires_its_legal_details_and_documents(): void
    {
        $this->patchJson('/api/v1/promoter/profile', ['profile_kind' => 'entreprise'])
            ->assertJsonValidationErrors(['company' => 'Indiquez la raison sociale de votre entreprise.']);

        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'organization_rccm', 'file' => $this->pdf('rccm.pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['document_type' => 'Ce document concerne une entreprise : passez d’abord votre compte en « Entreprise ».']);

        $this->patchJson('/api/v1/promoter/profile', ['company' => ['legal_name' => 'ADJEMIN']])
            ->assertJsonValidationErrors(['company' => 'Les informations d’entreprise ne concernent que les comptes « Entreprise ».']);

        $this->patchJson('/api/v1/promoter/profile', [
            'first_name' => 'Nathanaël', 'last_name' => 'Yao', 'profile_kind' => 'entreprise', 'job_title' => 'Directeur général',
            'company' => ['legal_name' => 'ADJEMIN', 'rccm' => 'n°?'],
        ])->assertJsonValidationErrors(['company.rccm' => 'Saisissez un numéro RCCM valide (ex. CI-ABJ-2024-B-12345).']);

        $this->patchJson('/api/v1/promoter/profile', [
            'first_name' => 'Nathanaël', 'last_name' => 'Yao', 'profile_kind' => 'entreprise', 'job_title' => 'Directeur général',
            'company' => [
                'legal_name' => 'ADJEMIN', 'rccm' => 'CI-ABJ-2024-B-12345', 'ncc' => '2412345 K',
                'headquarters' => ['address' => 'Angré 8e tranche, Cocody, Abidjan', 'commune_id' => Commune::query()->where('name', 'Cocody')->value('id'), 'location' => ['latitude' => 5.39871, 'longitude' => -3.98412]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.kind.value', 'entreprise')
            ->assertJsonPath('data.job_title', 'Directeur général')
            ->assertJsonPath('data.company.legal_name', 'ADJEMIN')
            ->assertJsonPath('data.company.ncc', '2412345 K')
            ->assertJsonPath('data.company.headquarters.location', ['latitude' => 5.39871, 'longitude' => -3.98412])
            ->assertJsonPath('data.verification.title', 'Identité et entreprise')
            ->assertJsonPath('data.documents.title', 'Documents de l’entreprise')
            ->assertJsonPath('data.documents.slots.*.key', ['identity', 'promoter_residence_certificate', 'organization_rccm', 'organization_dfe', 'organization_statutes'])
            ->assertJsonPath('data.documents.slots.0.label', 'Pièce d’identité du dirigeant')
            ->assertJsonPath('data.completion.missing.*.key', ['identity', 'residence', 'organization_rccm', 'organization_dfe']);

        $organization = Organization::query()->sole();
        $this->assertSame('administrateur', $organization->members()->sole()->role->value);

        $this->uploadIdentityAndResidence();
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'organization_rccm', 'file' => $this->pdf('rccm.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'organization_dfe', 'file' => $this->pdf('dfe.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.verification.status.value', 'soumis')
            ->assertJsonPath('data.documents.provided_required_count', 4);

        $this->assertSame(PromoterVerificationStatus::Soumis, $organization->fresh()->verification_status);
        $this->assertSame(2, Document::query()->where('owner_type', 'organization')->count());
    }

    public function test_changing_a_verified_element_sends_the_profile_back_for_verification(): void
    {
        $this->patchJson('/api/v1/promoter/profile', ['first_name' => 'Nathanaël', 'last_name' => 'Yao'])->assertOk();
        $this->uploadIdentityAndResidence();

        $profile = $this->promoter->promoterProfile;
        $profile->forceFill(['verification_status' => PromoterVerificationStatus::Valide, 'validated_at' => now()])->save();
        Document::query()->update(['verification_status' => DocumentVerificationStatus::Valide]);

        $this->getJson('/api/v1/promoter/profile')
            ->assertJsonPath('data.verification.state.value', 'verifie')
            ->assertJsonPath('data.verification.summary', 'Vérifiée')
            ->assertJsonPath('data.documents.slots.0.state.value', 'verifie');

        $residence = Document::query()->whereHas('documentType', fn ($query) => $query->where('code', 'promoter_residence_certificate'))->value('id');
        $this->deleteJson("/api/v1/promoter/profile/documents/{$residence}")
            ->assertJsonValidationErrors(['document' => 'Ce document a été vérifié : remplacez-le plutôt que de le retirer.']);

        $this->patchJson('/api/v1/promoter/profile', ['profile_kind' => 'entreprise', 'company' => ['legal_name' => 'ADJEMIN']])
            ->assertJsonValidationErrors(['profile_kind']);

        $this->patchJson('/api/v1/promoter/profile', ['address' => 'Riviera 3'])
            ->assertOk()
            ->assertJsonPath('data.verification.status.value', 'valide');

        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_passport', 'file' => $this->image('passeport.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.documents.slots.0.document.type.code', 'promoter_passport')
            ->assertJsonPath('data.verification.status.value', 'soumis');

        $this->assertSame(2, VerificationReview::query()->count());
        $this->assertSame(DocumentVerificationStatus::Remplace, Document::query()->whereHas('documentType', fn ($query) => $query->where('code', 'promoter_cni'))->sole()->verification_status);
    }

    public function test_other_documents_can_be_added_and_removed(): void
    {
        $first = $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_other', 'file' => $this->pdf('attestation.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('document_id');
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_other', 'file' => $this->pdf('plan.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.documents.others');

        $this->deleteJson("/api/v1/promoter/profile/documents/{$first}")
            ->assertOk()
            ->assertJsonPath('message', 'Document retiré')
            ->assertJsonCount(1, 'data.documents.others');

        $this->deleteJson("/api/v1/promoter/profile/documents/{$first}")->assertNotFound();
    }

    public function test_the_profile_photo_is_replaced_and_removed(): void
    {
        $this->post('/api/v1/promoter/profile/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['photo' => 'Choisissez une photo au format JPEG ou PNG.']);

        $url = $this->post('/api/v1/promoter/profile/photo', ['photo' => $this->image('moi.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.photo_url');

        $this->assertStringContainsString('/api/v1/media/', $url);
        $this->get($url)->assertOk();

        $this->deleteJson('/api/v1/promoter/profile/photo')->assertOk()->assertJsonPath('data.photo_url', null);
    }

    public function test_notification_preferences_have_defaults_and_can_be_changed(): void
    {
        $this->getJson('/api/v1/promoter/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.channels.*.enabled', [true, true, false])
            ->assertJsonPath('data.channels.1.hint', '+225 07 12 34 56 34')
            ->assertJsonPath('data.channels.2.hint', 'Non renseigné')
            ->assertJsonPath('data.alerts.*.key', ['offres', 'paiements', 'pointages']);

        $this->putJson('/api/v1/promoter/notification-preferences', ['channels' => ['email' => true]])
            ->assertJsonValidationErrors(['channels.email' => 'Ajoutez une adresse e-mail pour recevoir les notifications par e-mail.']);

        $this->putJson('/api/v1/promoter/notification-preferences', ['channels' => ['fax' => true]])->assertJsonValidationErrors(['channels']);

        $this->putJson('/api/v1/promoter/notification-preferences', ['channels' => ['sms' => false], 'alerts' => ['pointages' => false]])
            ->assertOk()
            ->assertJsonPath('message', 'Préférences enregistrées')
            ->assertJsonPath('data.channels.*.enabled', [true, false, false])
            ->assertJsonPath('data.alerts.*.enabled', [true, true, false]);
    }

    public function test_the_help_center_lists_the_promoter_questions_and_support_contacts(): void
    {
        $this->seed(HelpCenterSeeder::class);
        Setting::query()->create(['key' => 'support.phone', 'value' => '+225 27 22 00 00 00']);
        Setting::query()->create(['key' => 'support.email', 'value' => 'support@ochantier.ci']);

        $this->getJson('/api/v1/help')
            ->assertOk()
            ->assertJsonCount(5, 'faq')
            ->assertJsonPath('support.phone', '+225 27 22 00 00 00')
            ->assertJsonPath('support.email', 'support@ochantier.ci');
    }

    public function test_the_promoter_space_is_closed_to_commercials(): void
    {
        $commercial = User::factory()->commercial()->create();

        $this->withAccessToken($this->openSession($commercial)->accessToken)
            ->getJson('/api/v1/promoter/profile')
            ->assertForbidden();
    }

    public function test_a_single_sided_document_is_sent_as_one_file(): void
    {
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_passport', 'recto' => $this->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['recto' => 'Ce document n’a qu’une face : envoyez-le dans « file ».', 'file' => 'Ajoutez la photo du document.']);

        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'worker_cni', 'file' => $this->image('p.jpg')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['document_type' => 'Ce type de document n’est pas accepté.']);

        $this->assertSame(PromoterProfileKind::Particulier, $this->promoter->promoterProfile->fresh()->profile_kind);
    }

    private function uploadIdentityAndResidence(): void
    {
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_cni', 'recto' => $this->image('recto.jpg'), 'verso' => $this->image('verso.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_residence_certificate', 'file' => $this->pdf('residence.pdf')], ['Accept' => 'application/json'])->assertCreated();
    }
}
