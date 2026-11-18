<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Models\Contractor;
use App\Models\Notification;
use App\Models\User;
use App\Models\VerificationReview;

class ContractorProfileTest extends ContractorTestCase
{
    public function test_the_profile_must_be_chosen_first(): void
    {
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.contractor.profile_chosen', false)
            ->assertJsonPath('data.contractor.profile', null);

        $this->getJson('/api/v1/contractor/profile')
            ->assertNotFound()
            ->assertJsonPath('message', 'Choisissez d’abord votre profil : entreprise ou indépendant.');

        $this->postJson('/api/v1/contractor/profile', ['kind' => 'ouvrier'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.kind.0', 'Choisissez votre profil : entreprise ou indépendant.');
    }

    public function test_choosing_a_profile_creates_a_prefilled_draft(): void
    {
        $this->postJson('/api/v1/contractor/profile', ['kind' => 'independant'])
            ->assertCreated()
            ->assertJsonPath('message', 'Profil choisi : complétez votre dossier.')
            ->assertJsonPath('data.kind.value', 'independant')
            ->assertJsonPath('data.verification_type', 'KYC')
            ->assertJsonPath('data.contact.phone_number', '0712345678')
            ->assertJsonPath('data.verification.state.value', 'brouillon')
            ->assertJsonPath('data.verification.can_submit', true)
            ->assertJsonPath('data.limits.can_respond', false)
            ->assertJsonPath('data.completion.complete', false)
            ->assertJsonPath('data.completion.missing.0.key', 'last_name')
            ->assertJsonPath('data.document_types.0.code', 'contractor_cni')
            ->assertJsonPath('data.document_types.0.requires_number', true)
            ->assertJsonPath('data.document_types.4.code', 'contractor_trade_certificate');

        $this->assertMatchesRegularExpression('/^ENT-\d{5}$/', Contractor::query()->sole()->reference);

        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.contractor.profile_chosen', true)
            ->assertJsonPath('data.contractor.profile.verification_state.value', 'brouillon');

        $this->postJson('/api/v1/contractor/profile', ['kind' => 'entreprise'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.kind.0', 'Votre profil est déjà choisi : modifiez votre dossier.');
    }

    public function test_an_independent_is_submitted_for_verification(): void
    {
        $this->completeIndependent();

        $this->getJson('/api/v1/contractor/profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.initials', 'YS')
            ->assertJsonPath('data.identity.birth_date', '1990-04-12')
            ->assertJsonPath('data.activity.trades.0.code', 'electricien')
            ->assertJsonPath('data.documents.0.number_last4', '0123')
            ->assertJsonPath('data.completion.percent', 100);

        $this->postJson('/api/v1/contractor/profile/submit', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.certify.0', 'Votre consentement est nécessaire pour soumettre le dossier.');

        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Dossier soumis à vérification.')
            ->assertJsonPath('data.verification.status.value', 'soumis')
            ->assertJsonPath('data.verification.state.value', 'en_verification')
            ->assertJsonPath('data.verification.can_edit', false);

        $review = VerificationReview::query()->sole();
        $this->assertSame('contractor', $review->subject_type);
        $this->assertSame('independant', $review->snapshot['kind']);
        $this->assertSame(['electricien', 'plombier'], $review->snapshot['trades']);
        $this->assertCount(1, $review->snapshot['documents']);
        $this->assertSame('Dossier soumis', Notification::query()->sole()->title);

        $this->patchJson('/api/v1/contractor/profile', ['description' => 'Électricité générale'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.profile.0', 'Votre dossier est en cours de vérification : vous pourrez le modifier après la décision.');

        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.profile.0', 'Votre dossier est déjà en cours de vérification.');
    }

    public function test_an_incomplete_file_lists_what_is_missing(): void
    {
        $this->postJson('/api/v1/contractor/profile', ['kind' => 'independant', 'last_name' => 'Koffi'])->assertCreated();

        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.first_name.0', 'Prénoms : information manquante.')
            ->assertJsonPath('errors.trades.0', 'Métiers exercés : information manquante.')
            ->assertJsonPath('errors.identity.0', 'Pièce d’identité (numéro, recto et verso) : information manquante.')
            ->assertJsonMissingPath('errors.last_name');

        $this->assertSame(ContractorVerificationStatus::Brouillon, Contractor::query()->sole()->verification_status);
    }

    public function test_independent_fields_are_validated(): void
    {
        $this->postJson('/api/v1/contractor/profile', ['kind' => 'independant'])->assertCreated();

        $this->patchJson('/api/v1/contractor/profile', [
            'birth_date' => now()->subYears(17)->toDateString(),
            'legal_name' => 'BTP Services',
            'experience_years' => 61,
            'trade_ids' => [],
            'dial_code' => '225',
            'phone_number' => '07123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.birth_date.0', 'Vous devez être majeur.')
            ->assertJsonPath('errors.legal_name.0', 'Ce champ ne concerne pas votre type de profil.')
            ->assertJsonPath('errors.experience_years.0', 'Indiquez un nombre d’années valide.')
            ->assertJsonPath('errors.trade_ids.0', 'Choisissez au moins un métier.')
            ->assertJsonPath('errors.phone_number.0', 'Saisissez un numéro à 10 chiffres.');
    }

    public function test_the_identity_document_needs_its_number_and_both_sides(): void
    {
        $this->postJson('/api/v1/contractor/profile', ['kind' => 'independant'])->assertCreated();

        $this->upload(['document_type' => 'contractor_cni', 'recto' => $this->image()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.number.0', 'Indiquez le numéro du document.')
            ->assertJsonPath('errors.verso.0', 'Ajoutez le verso du document.');

        $this->upload(['document_type' => 'contractor_rccm', 'file' => $this->pdf()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.document_type.0', 'Cette pièce n’est pas demandée pour votre profil.');

        $this->upload(['document_type' => 'contractor_passport', 'number' => 'P1234567', 'file' => $this->image()])
            ->assertCreated()
            ->assertJsonPath('message', 'Passeport ajouté');

        $this->upload(['document_type' => 'contractor_cni', 'number' => 'CI-DEMO-000123', 'recto' => $this->image(), 'verso' => $this->image()])
            ->assertCreated()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.type.code', 'contractor_cni');

        $id = $this->getJson('/api/v1/contractor/profile')->json('data.documents.0.id');
        $this->deleteJson('/api/v1/contractor/profile/documents/'.$id)
            ->assertOk()
            ->assertJsonPath('message', 'Pièce retirée')
            ->assertJsonCount(0, 'data.documents');
    }

    public function test_a_company_file_is_completed_with_its_representative(): void
    {
        $this->postJson('/api/v1/contractor/profile', [
            'kind' => 'entreprise',
            'legal_name' => 'Koffi BTP Services',
            'legal_form' => 'SARL',
            'rccm' => 'CI-ABJ-2024-B-12345',
            'representative_name' => 'Yao Serge Koffi',
            'email' => 'contact@koffi-btp.ci',
            'city_id' => $this->city(),
            'address' => 'Plateau, avenue Chardy',
            'trade_ids' => [$this->trade('macon')],
            'zone_city_ids' => [$this->city(), $this->city('Bouaké')],
            'experience_years' => 12,
        ])
            ->assertCreated()
            ->assertJsonPath('data.verification_type', 'KYB')
            ->assertJsonPath('data.company.legal_name', 'Koffi BTP Services')
            ->assertJsonPath('data.company.rccm', 'CI-ABJ-2024-B-12345')
            ->assertJsonPath('data.identity', null)
            ->assertJsonCount(1, 'data.activity.zones')
            ->assertJsonPath('data.activity.zones.0.name', 'Bouaké')
            ->assertJsonPath('data.document_types.0.requires_number', false)
            ->assertJsonPath('data.options.legal_forms.0', 'SARL')
            ->assertJsonPath('data.completion.missing.0.key', 'identity');

        $this->patchJson('/api/v1/contractor/profile', ['legal_form' => 'SNC', 'birth_date' => '1990-01-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.legal_form.0', 'Choisissez la forme juridique.')
            ->assertJsonPath('errors.birth_date.0', 'Ce champ ne concerne pas votre type de profil.');

        $this->upload(['document_type' => 'contractor_trade_certificate', 'file' => $this->pdf()])->assertUnprocessable();
        $this->upload(['document_type' => 'contractor_rccm', 'file' => $this->pdf('rccm.pdf')])->assertCreated();
        $this->upload(['document_type' => 'contractor_cni', 'recto' => $this->image(), 'verso' => $this->image()])
            ->assertCreated()
            ->assertJsonPath('data.completion.complete', true);

        $this->post('/api/v1/contractor/profile/photo', ['photo' => $this->image('logo.png')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Logo mis à jour');
        $this->assertNotNull($this->getJson('/api/v1/contractor/profile')->json('data.photo_url'));

        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'en_verification');
    }

    public function test_the_profile_kind_can_change_while_the_file_is_a_draft(): void
    {
        $this->postJson('/api/v1/contractor/profile', ['kind' => 'independant', 'last_name' => 'Koffi', 'first_name' => 'Yao'])->assertCreated();

        $this->patchJson('/api/v1/contractor/profile', ['kind' => 'entreprise', 'legal_name' => 'Koffi BTP', 'legal_form' => 'GIE'])
            ->assertOk()
            ->assertJsonPath('data.kind.value', 'entreprise')
            ->assertJsonPath('data.name', 'Koffi BTP');
    }

    public function test_the_contractor_space_is_closed_to_other_accounts(): void
    {
        $this->actingAsContractor(User::factory()->promoter()->create());

        $this->getJson('/api/v1/contractor/profile')->assertForbidden();
    }
}
