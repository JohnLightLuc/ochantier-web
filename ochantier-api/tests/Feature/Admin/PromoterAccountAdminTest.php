<?php

namespace Tests\Feature\Admin;

use App\Enums\DocumentVerificationStatus;
use App\Enums\PromoterProfileKind;
use App\Enums\PromoterVerificationStatus;
use App\Enums\UserStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Organization;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Promoteurs : création et modification d’un compte, ajout d’une pièce, listes du formulaire.
 */
class PromoterAccountAdminTest extends PromoterTestCase
{
    private Admin $superAdmin;

    private Admin $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAccessSeeder::class);
        $this->superAdmin = Admin::factory()->role('super_admin')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
        $this->operations = Admin::factory()->role('operations')->create();
    }

    /**
     * @return $this
     */
    private function asAdmin(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyForm(array $overrides = []): array
    {
        return [
            'first_name' => 'Konan',
            'last_name' => 'Kouassi',
            'dial_code' => '225',
            'phone_number' => '07 01 02 03 04',
            'email' => null,
            'profile_kind' => 'entreprise',
            'job_title' => 'Directeur général',
            'commune_id' => $this->commune(),
            'company' => [
                'legal_name' => 'Bâtir Afrique',
                'legal_form' => 'SARL',
                'rccm' => 'CI-ABJ-2024-B-12345',
                'ncc' => '2401234 A',
                'headquarters' => ['address' => 'Rue des Jardins, Cocody', 'commune_id' => $this->commune()],
            ],
            ...$overrides,
        ];
    }

    public function test_an_admin_creates_a_company_account_and_the_promoter_activates_it_in_the_app(): void
    {
        $response = $this->asAdmin($this->superAdmin)->postJson('/api/v1/admin/promoters', $this->companyForm())
            ->assertCreated()
            ->assertJsonPath('message', 'Compte promoteur créé : invitation envoyée par SMS.')
            ->assertJsonPath('data.kind.value', 'entreprise')
            ->assertJsonPath('data.company.legal_name', 'Bâtir Afrique')
            ->assertJsonPath('data.account.status.value', 'en_attente_activation');

        $profile = PromoterProfile::query()->findOrFail($response->json('data.id'));
        $user = $profile->user;
        $this->assertSame(UserStatus::EnAttenteActivation, $user->status);
        $this->assertSame('+2250701020304', $user->phone);
        $this->assertSame(PromoterVerificationStatus::Brouillon, $profile->verification_status);
        $this->assertSame('CI-ABJ-2024-B-12345', Organization::query()->where('owner_user_id', $user->id)->value('rccm'));
        $this->assertSame('+2250701020304', $this->sms->sent[0]['phone']);
        $this->assertStringContainsString('Créer votre compte', $this->sms->sent[0]['message']);
        $this->assertSame(1, AuditLog::query()->where('action', 'promoter.created')->where('subject_id', $profile->id)->count());

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'entreprise', 'company_name' => 'Bâtir Afrique', 'dial_code' => '225', 'phone_number' => '0701020304', 'accept_terms' => true])->assertSuccessful();
        $this->postJson('/api/v1/auth/promoter/register/verify', ['dial_code' => '225', 'phone_number' => '0701020304', 'code' => '123456', ...$this->device()])->assertCreated();

        $user->refresh();
        $this->assertSame(UserStatus::Actif, $user->status);
        $this->assertSame('Konan', $user->first_name);
        $this->assertSame(['cgu', 'privacy'], $user->consents()->pluck('consent_type')->map->value->sort()->values()->all());
        $this->assertSame('CI-ABJ-2024-B-12345', Organization::query()->where('owner_user_id', $user->id)->value('rccm'));
    }

    public function test_creation_checks_the_contact_and_the_permission(): void
    {
        $this->asAdmin($this->operations)->postJson('/api/v1/admin/promoters', $this->companyForm())->assertForbidden();

        $this->asAdmin($this->superAdmin)->postJson('/api/v1/admin/promoters', $this->companyForm(['phone_number' => '0712345634']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number' => 'Ce numéro est déjà utilisé par un autre compte.']);

        $this->asAdmin($this->superAdmin)->postJson('/api/v1/admin/promoters', $this->companyForm(['dial_code' => null, 'phone_number' => null, 'company' => ['legal_name' => '']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number', 'email', 'company.legal_name']);

        $this->asAdmin($this->superAdmin)->postJson('/api/v1/admin/promoters', $this->companyForm(['dial_code' => null, 'phone_number' => null, 'email' => 'Contact@Batir.ci', 'send_invitation' => false]))
            ->assertCreated()
            ->assertJsonPath('message', 'Compte promoteur créé, en attente d’activation.')
            ->assertJsonPath('data.email', 'contact@batir.ci');
        $this->assertSame([], $this->sms->sent);
    }

    public function test_editing_a_validated_file_keeps_it_validated_and_a_new_number_closes_the_sessions(): void
    {
        $user = User::factory()->promoter()->create(['first_name' => 'Awa', 'last_name' => 'Bamba', 'dial_code' => '225', 'phone_number' => '0711111111', 'phone_verified_at' => now()]);
        $profile = $user->promoterProfile;
        $profile->update(['verification_status' => PromoterVerificationStatus::Valide, 'validated_at' => now()]);
        $session = $this->openSession($user);

        $form = ['first_name' => 'Awa', 'last_name' => 'Bamba-Koffi', 'dial_code' => '225', 'phone_number' => '0799999999', 'email' => null, 'profile_kind' => 'particulier', 'commune_id' => $this->commune(), 'address' => 'Riviera 3'];

        $this->asAdmin($this->operations)->patchJson("/api/v1/admin/promoters/{$profile->id}", [...$form, 'profile_kind' => 'entreprise', 'company' => ['legal_name' => 'Bamba BTP']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['profile_kind' => 'Le dossier a déjà été transmis pour vérification : le type de compte ne peut plus être modifié.']);

        $this->asAdmin($this->operations)->patchJson("/api/v1/admin/promoters/{$profile->id}", $form)
            ->assertOk()
            ->assertJsonPath('message', 'Promoteur mis à jour.')
            ->assertJsonPath('data.last_name', 'Bamba-Koffi')
            ->assertJsonPath('data.status.value', 'valide');

        $user->refresh();
        $this->assertSame(PromoterVerificationStatus::Valide, $profile->fresh()->verification_status);
        $this->assertSame('+2250799999999', $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($session->session->fresh()->revoked_at);

        $audit = AuditLog::query()->where('action', 'promoter.updated')->sole();
        $this->assertEquals(['user.last_name' => 'Bamba', 'user.phone_number' => '0711111111', 'profile.address' => null, 'profile.commune_id' => null], $audit->before_values);
        $this->assertSame('Bamba-Koffi', $audit->after_values['user.last_name']);
    }

    public function test_a_document_added_by_an_admin_waits_for_verification_without_reopening_a_validated_file(): void
    {
        $profile = $this->promoter->promoterProfile;
        $profile->update(['verification_status' => PromoterVerificationStatus::Valide, 'validated_at' => now()]);

        $this->asAdmin($this->operations)->post("/api/v1/admin/promoters/{$profile->id}/documents", ['document_type' => 'promoter_residence_certificate', 'file' => $this->pdf('residence.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message', 'Certificat de résidence ajouté au dossier.');

        $document = Document::query()->where('owner_id', $profile->id)->sole();
        $this->assertSame(DocumentVerificationStatus::EnAttente, $document->verification_status);
        $this->assertSame($this->operations->id, $document->uploaded_by_id);
        $this->assertSame(PromoterVerificationStatus::Valide, $profile->fresh()->verification_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'document.uploaded')->count());
    }

    public function test_the_form_options_list_the_kinds_the_places_and_the_document_types(): void
    {
        $this->asAdmin($this->operations)->getJson('/api/v1/admin/promoters/form-options')
            ->assertOk()
            ->assertJsonPath('data.profile_kinds.2.value', PromoterProfileKind::Entreprise->value)
            ->assertJsonPath('data.cities.0.name', 'Abidjan')
            ->assertJsonFragment(['code' => 'organization_rccm', 'audience' => 'organization']);
    }
}
