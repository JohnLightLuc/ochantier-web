<?php

namespace Tests\Feature\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\SiteStatus;
use App\Models\ConstructionType;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class SiteTest extends PromoterTestCase
{
    public function test_a_draft_only_needs_a_title(): void
    {
        $this->postJson('/api/v1/promoter/sites', [])
            ->assertJsonValidationErrors(['title' => 'Donnez au moins un titre au brouillon.']);

        $response = $this->postJson('/api/v1/promoter/sites', ['title' => 'Duplex Bingerville'])
            ->assertCreated()
            ->assertJsonPath('message', 'Brouillon enregistré')
            ->assertJsonPath('data.title', 'Duplex Bingerville')
            ->assertJsonPath('data.status.value', 'brouillon')
            ->assertJsonPath('data.display_status', ['value' => 'brouillon', 'label' => 'Brouillon'])
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.responsible.role', 'Moi-même')
            ->assertJsonPath('data.missing_for_publication.*.key', ['photo', 'location', 'dates', 'needs'])
            ->assertJsonPath('data.documents', []);

        $this->assertMatchesRegularExpression('/^CH-'.now()->format('Y').'-\d{4}$/', $response->json('data.reference'));
        $this->assertSame(SiteStatus::Brouillon, Site::query()->sole()->statusHistories()->sole()->to_status);
    }

    public function test_the_draft_is_completed_with_its_location_dates_and_type(): void
    {
        $site = $this->createSite();
        $villa = ConstructionType::query()->where('code', 'villa')->value('id');
        $start = now()->addDays(10)->toDateString();

        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['planned_start_date' => now()->subDay()->toDateString()])
            ->assertJsonValidationErrors(['planned_start_date' => 'La date de début ne peut pas être passée.']);

        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['planned_start_date' => $start, 'planned_end_date' => now()->addDays(5)->toDateString()])
            ->assertJsonValidationErrors(['planned_end_date' => 'La date de fin doit suivre la date de début.']);

        $updated = $this->patchJson("/api/v1/promoter/sites/{$site['id']}", [
            'description' => 'Duplex 4 pièces sur terrain de 500 m².',
            'work_type' => 'construction_neuve',
            'construction_type_id' => $villa,
            'address' => 'Riviera Palmeraie, Cocody, Abidjan',
            'commune_id' => $this->commune(),
            'location' => ['latitude' => 5.37214, 'longitude' => -3.95823, 'accuracy_m' => 12],
            'planned_start_date' => $start,
            'planned_end_date' => now()->addDays(39)->toDateString(),
            'lock_version' => $site['lock_version'],
        ])
            ->assertOk()
            ->assertJsonPath('data.work_type', ['value' => 'construction_neuve', 'label' => 'Construction neuve'])
            ->assertJsonPath('data.property_type.label', 'Villa')
            ->assertJsonPath('data.address.commune.name', 'Cocody')
            ->assertJsonPath('data.address.location', ['latitude' => 5.37214, 'longitude' => -3.95823])
            ->assertJsonPath('data.start_date', $start)
            ->assertJsonPath('data.duration_days', 30)
            ->assertJsonPath('data.missing_for_publication.*.key', ['photo', 'needs'])
            ->json('data');

        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['planned_end_date' => now()->addDays(19)->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.duration_days', 10);

        $this->patchJson("/api/v1/promoter/sites/{$site['id']}", ['title' => 'Autre titre', 'lock_version' => $updated['lock_version']])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ce chantier a été modifié sur un autre appareil. Rechargez-le avant de continuer.');
    }

    public function test_the_same_offline_draft_is_never_created_twice(): void
    {
        $payload = ['title' => 'Villa Riviera', 'client_uuid' => '3f1d8a2b-6c4e-4f7a-9b2c-1d3e5f7a9b0c'];

        $id = $this->postJson('/api/v1/promoter/sites', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/promoter/sites', $payload)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, Site::query()->count());
    }

    public function test_a_photo_and_documents_are_attached_to_the_draft(): void
    {
        $site = $this->createSite();

        $url = $this->post("/api/v1/promoter/sites/{$site['id']}/photo", ['photo' => $this->image('chantier.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Photo ajoutée')
            ->assertJsonPath('data.missing_for_publication.*.key', ['location', 'dates', 'needs'])
            ->json('data.photo_url');
        $this->get($url)->assertOk();

        $plan = $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_plan', 'file' => $this->pdf('Plan architectural.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type.label', 'Plan architectural')
            ->assertJsonPath('data.files.0.name', 'Plan architectural.pdf')
            ->json('data.id');

        $zip = UploadedFile::fake()->create('Photos du terrain.zip', 2048, 'application/zip');
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_plan', 'file' => $zip], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors(['file' => 'Format non accepté pour ce document.']);
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_other', 'file' => $zip], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->post("/api/v1/promoter/sites/{$site['id']}/documents", ['document_type' => 'site_plan', 'file' => $this->pdf('Plan RDC.pdf')], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->getJson("/api/v1/promoter/sites/{$site['id']}")->assertJsonCount(3, 'data.documents');

        $this->deleteJson("/api/v1/promoter/sites/{$site['id']}/documents/{$plan}")->assertOk()->assertJsonPath('data', null);
        $this->deleteJson("/api/v1/promoter/sites/{$site['id']}/documents/{$plan}")->assertNotFound();
        $this->getJson("/api/v1/promoter/sites/{$site['id']}")->assertJsonCount(2, 'data.documents');

        $this->deleteJson("/api/v1/promoter/sites/{$site['id']}/photo")->assertOk()->assertJsonPath('data.photo_url', null);
    }

    public function test_the_list_is_filtered_by_tab_and_searched(): void
    {
        $this->createSite(['title' => 'Duplex Bingerville', 'address' => 'Bingerville']);
        $palmiers = $this->createSite(['title' => 'Résidence Les Palmiers', 'address' => 'Cocody, Abidjan']);
        $horizon = $this->createSite(['title' => 'Immeuble Horizon', 'address' => 'Marcory, Abidjan']);
        Site::query()->whereKey($palmiers['id'])->update(['status' => SiteStatus::EnCours, 'planned_start_date' => now()->subDays(11), 'planned_end_date' => now()->addDays(18), 'planned_duration_days' => 30]);
        Site::query()->whereKey($horizon['id'])->update(['status' => SiteStatus::Termine, 'planned_start_date' => '2025-02-03', 'planned_end_date' => '2026-07-20', 'planned_duration_days' => 533]);

        $this->getJson('/api/v1/promoter/sites')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.counts', ['tous' => 3, 'brouillons' => 1, 'recherche' => 0, 'en_cours' => 1, 'termines' => 1]);

        $this->getJson('/api/v1/promoter/sites?filter=en_cours')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_status.label', 'En cours')
            ->assertJsonPath('data.0.schedule.label', 'Jour 12 / 30 · fin le '.now()->addDays(18)->locale('fr')->translatedFormat('j M Y'))
            ->assertJsonPath('data.0.can_edit', false);

        $this->getJson('/api/v1/promoter/sites?filter=termines')
            ->assertJsonPath('data.0.schedule', ['label' => 'Livré le 20 juil. 2026', 'tag' => '533 jours']);

        $this->getJson('/api/v1/promoter/sites?search=abidjan')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.tous', 2);

        $this->getJson('/api/v1/promoter/sites?filter=autre')->assertJsonValidationErrors(['filter']);
    }

    public function test_a_published_site_can_no_longer_be_edited_or_deleted(): void
    {
        $draft = $this->createSite();
        $published = $this->createSite(['title' => 'Résidence Les Acacias']);
        Site::query()->whereKey($published['id'])->update(['status' => SiteStatus::Soumis]);

        $this->getJson("/api/v1/promoter/sites/{$published['id']}")
            ->assertJsonPath('data.display_status', ['value' => 'recherche', 'label' => 'Recherche d’ouvriers en cours'])
            ->assertJsonPath('data.missing_for_publication', []);

        $this->patchJson("/api/v1/promoter/sites/{$published['id']}", ['title' => 'Autre'])
            ->assertJsonValidationErrors(['site' => 'Ce chantier est publié : il ne peut plus être modifié depuis l’application.']);
        $this->deleteJson("/api/v1/promoter/sites/{$published['id']}")
            ->assertJsonValidationErrors(['site' => 'Seul un brouillon peut être supprimé.']);

        $this->deleteJson("/api/v1/promoter/sites/{$draft['id']}")->assertOk()->assertJsonPath('data', null);
        $this->getJson("/api/v1/promoter/sites/{$draft['id']}")->assertNotFound();
    }

    public function test_the_responsible_is_the_promoter_or_a_user_of_the_company(): void
    {
        $organization = $this->promoter->ownedOrganizations()->create(['legal_name' => 'ADJEMIN']);
        $technician = User::factory()->promoter()->create(['first_name' => 'Jean', 'last_name' => 'Kouamé', 'dial_code' => '225', 'phone_number' => '0700000000']);
        $organization->members()->create(['user_id' => $technician->id, 'role' => OrganizationRole::ResponsableChantier, 'status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);
        $stranger = User::factory()->promoter()->create();

        $this->getJson('/api/v1/promoter/sites/form-options')
            ->assertOk()
            ->assertJsonPath('data.work_types.*.value', ['construction_neuve', 'renovation', 'extension', 'finitions'])
            ->assertJsonPath('data.property_types.*.code', ['villa', 'duplex', 'immeuble', 'local_commercial', 'bureaux', 'equipement_public', 'batiment_industriel', 'voirie_reseaux'])
            ->assertJsonPath('data.responsibles.0.role', 'Moi-même')
            ->assertJsonPath('data.responsibles.1.name', 'Jean Kouamé')
            ->assertJsonPath('data.responsibles.1.phone', '+225 07 00 00 00 00')
            ->assertJsonPath('data.document_types.*.code', ['site_building_permit', 'site_plan', 'site_quote', 'site_specifications', 'site_other', 'site_photo']);

        $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera', 'responsible_user_id' => $stranger->id])
            ->assertJsonValidationErrors(['responsible_user_id' => 'Choisissez vous-même ou un utilisateur de votre équipe.']);

        $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera', 'responsible_user_id' => $technician->id])
            ->assertCreated()
            ->assertJsonPath('data.responsible.name', 'Jean Kouamé');

        $this->assertSame($organization->id, Site::query()->sole()->organization_id);
    }

    public function test_sites_of_other_promoters_are_not_found(): void
    {
        $site = $this->createSite();

        $this->actingAsPromoter(User::factory()->promoter()->create())
            ->getJson("/api/v1/promoter/sites/{$site['id']}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Élément introuvable.');

        $this->actingAsPromoter(User::factory()->commercial()->create())
            ->getJson('/api/v1/promoter/sites')
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function createSite(array $data = []): array
    {
        return $this->postJson('/api/v1/promoter/sites', ['title' => 'Duplex Bingerville', ...$data])->assertCreated()->json('data');
    }
}
