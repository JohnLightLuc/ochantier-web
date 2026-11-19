<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Enums\DocumentVerificationStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Notification;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;

class ContractorReviewTest extends ContractorTestCase
{
    private string $adminToken;

    private string $contractorToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAccessSeeder::class);
        $this->adminToken = $this->adminTokenFor(Admin::factory()->role('operations')->create());
        $this->contractorToken = $this->openSession($this->user)->accessToken;
        $this->withAccessToken($this->contractorToken);
    }

    public function test_the_back_office_lists_files_to_verify_first(): void
    {
        $this->submitIndependent();
        Contractor::query()->create(['reference' => 'ENT-99999', 'kind' => 'entreprise', 'name' => 'Ancienne entreprise', 'verification_status' => 'valide']);

        $this->asAdmin()->getJson('/api/v1/admin/contractors')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.soumis', 1)
            ->assertJsonPath('meta.counts.valide', 1)
            ->assertJsonPath('data.0.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.0.verification_state.value', 'en_verification')
            ->assertJsonPath('data.0.trades.0.code', 'electricien')
            ->assertJsonPath('data.1.has_account', false);

        $this->asAdmin()->getJson('/api/v1/admin/contractors?verification_status=valide&search=ancienne')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $id = Contractor::query()->where('reference', '!=', 'ENT-99999')->value('id');
        $this->asAdmin()->getJson('/api/v1/admin/contractors/'.$id)
            ->assertOk()
            ->assertJsonPath('data.identity.last_name', 'Koffi')
            ->assertJsonPath('data.account.phone', '+225 07 12 34 56 78')
            ->assertJsonPath('data.reviews.0.submission_number', 1)
            ->assertJsonPath('data.actions.approve', true)
            ->assertJsonCount(1, 'data.documents');
    }

    public function test_an_approved_contractor_can_respond_until_a_sensitive_change(): void
    {
        $this->submitIndependent();
        $contractor = Contractor::query()->sole();

        $this->asAdmin()->postJson('/api/v1/admin/contractors/'.$contractor->id.'/approve')
            ->assertOk()
            ->assertJsonPath('message', 'Profil vérifié.')
            ->assertJsonPath('data.verification.state.value', 'verifie')
            ->assertJsonPath('data.reviews.0.status.value', 'valide')
            ->assertJsonPath('data.actions.approve', false);

        $this->assertSame(DocumentVerificationStatus::Valide, Document::query()->sole()->verification_status);
        $this->assertSame('Votre profil est vérifié', Notification::query()->latest('created_at')->first()->title);
        $this->assertSame(1, AuditLog::query()->where('action', 'contractor.approved')->count());

        $this->withAccessToken($this->contractorToken)->getJson('/api/v1/contractor/profile')
            ->assertJsonPath('data.limits.can_respond', true)
            ->assertJsonPath('data.verification.can_submit', false)
            ->assertJsonPath('data.verification.edit_warning', 'La modification de données sensibles est tracée et relance la vérification de votre profil.');

        $this->patchJson('/api/v1/contractor/profile', ['description' => 'Électricité générale', 'zone_city_ids' => [$this->city('Bouaké')]])
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'verifie');

        $this->patchJson('/api/v1/contractor/profile', ['kind' => 'entreprise', 'legal_name' => 'Koffi BTP', 'legal_form' => 'SARL'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.kind.0', 'Votre dossier est vérifié : le type de profil ne peut plus être modifié. Contactez l’assistance.');

        $this->patchJson('/api/v1/contractor/profile', ['trade_ids' => [$this->trade('macon')]])
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'brouillon')
            ->assertJsonPath('data.limits.can_respond', false);

        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'en_verification');

        $this->assertSame('Modification soumise', Notification::query()->latest('created_at')->first()->title);
        $this->assertSame(2, $contractor->verificationReviews()->count());
    }

    public function test_corrections_reject_the_documents_and_reopen_the_file(): void
    {
        $this->submitIndependent();
        $contractor = Contractor::query()->sole();
        $document = Document::query()->sole();

        $this->asAdmin()->postJson('/api/v1/admin/contractors/'.$contractor->id.'/corrections', ['reason' => 'Pièce d’identité illisible.', 'document_ids' => [$document->id]])
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'a_corriger')
            ->assertJsonPath('data.verification.reason', 'Pièce d’identité illisible.');

        $this->assertSame(DocumentVerificationStatus::Rejete, $document->fresh()->verification_status);
        $this->assertSame('Des corrections sont demandées', Notification::query()->latest('created_at')->first()->title);

        $this->asAdmin()->postJson('/api/v1/admin/contractors/'.$contractor->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonPath('errors.contractor.0', 'Le dossier n’est pas en attente de vérification (statut : À corriger).');

        $this->withAccessToken($this->contractorToken)
            ->upload(['document_type' => 'contractor_cni', 'number' => 'CI-DEMO-000456', 'recto' => $this->image(), 'verso' => $this->image()])
            ->assertCreated()
            ->assertJsonPath('data.documents.0.number_last4', '0456');

        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'en_verification')
            ->assertJsonPath('data.verification.reason', null);
    }

    public function test_a_rejected_file_can_be_corrected_and_resubmitted(): void
    {
        $this->submitIndependent();
        $contractor = Contractor::query()->sole();

        $this->asAdmin()->postJson('/api/v1/admin/contractors/'.$contractor->id.'/reject', ['reason' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Le motif est trop court.');

        $this->asAdmin()->postJson('/api/v1/admin/contractors/'.$contractor->id.'/reject', ['reason' => 'Identité incohérente avec la pièce.'])
            ->assertOk()
            ->assertJsonPath('data.verification.state', ['value' => 'rejete', 'label' => 'Rejeté']);

        $this->withAccessToken($this->contractorToken)->getJson('/api/v1/contractor/profile')
            ->assertJsonPath('data.verification.reason', 'Identité incohérente avec la pièce.')
            ->assertJsonPath('data.verification.can_submit', true);

        $this->patchJson('/api/v1/contractor/profile', ['first_name' => 'Yao'])->assertOk();
        $this->postJson('/api/v1/contractor/profile/submit', ['certify' => true])->assertOk();

        $this->assertSame(ContractorVerificationStatus::Soumis, $contractor->fresh()->verification_status);
    }

    public function test_a_suspended_contractor_keeps_read_access_only(): void
    {
        $this->completeIndependent();
        $contractor = Contractor::query()->sole();

        $this->asAdmin()->postJson('/api/v1/admin/contractors/'.$contractor->id.'/suspension', ['reason' => 'Documents frauduleux.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'suspendu')
            ->assertJsonPath('data.actions.reactivate', true);

        $this->withAccessToken($this->contractorToken)->getJson('/api/v1/contractor/profile')
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'suspendu')
            ->assertJsonPath('data.verification.reason', 'Documents frauduleux.')
            ->assertJsonPath('data.verification.can_edit', false);

        $this->patchJson('/api/v1/contractor/profile', ['description' => 'Test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.profile.0', 'Votre compte est suspendu : contactez l’assistance O’CHANTIER.');

        $this->asAdmin()->deleteJson('/api/v1/admin/contractors/'.$contractor->id.'/suspension')
            ->assertOk()
            ->assertJsonPath('data.verification.state.value', 'brouillon');

        $this->asAdmin()->deleteJson('/api/v1/admin/contractors/'.$contractor->id.'/suspension')
            ->assertUnprocessable()
            ->assertJsonPath('errors.contractor.0', 'Ce compte n’est pas suspendu.');

        $this->assertSame(ContractorStatus::Actif, $contractor->fresh()->status);
    }

    public function test_only_authorised_admins_decide(): void
    {
        $this->submitIndependent();
        $contractor = Contractor::query()->sole();
        $support = $this->adminTokenFor(Admin::factory()->role('support')->create());

        $this->withAccessToken($support)->getJson('/api/v1/admin/contractors/'.$contractor->id)->assertOk();
        $this->withAccessToken($support)->postJson('/api/v1/admin/contractors/'.$contractor->id.'/approve')->assertForbidden();
    }

    public function test_the_demo_command_decides_outside_production(): void
    {
        $this->submitIndependent();

        $this->artisan('ochantier:simulate-contractor-review', ['contractor' => '0712345678', 'decision' => 'valider'])
            ->expectsOutputToContain('Yao Serge Koffi')
            ->assertSuccessful();

        $this->assertSame(ContractorVerificationStatus::Valide, Contractor::query()->sole()->verification_status);

        $this->artisan('ochantier:simulate-contractor-review', ['contractor' => 'ENT-00000', 'decision' => 'valider'])->assertFailed();

        Contractor::query()->sole()->forceFill(['phone_number' => '0713323566'])->save();
        $this->artisan('ochantier:simulate-contractor-review', ['contractor' => '+225 07 13 32 35 66', 'decision' => 'suspendre'])
            ->expectsOutputToContain('Yao Serge Koffi')
            ->assertSuccessful();

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('ochantier:simulate-contractor-review', ['contractor' => '0712345678', 'decision' => 'suspendre'])
            ->expectsOutputToContain('Simulation interdite en production')
            ->assertFailed();
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }

    /**
     * @return $this
     */
    private function asAdmin(): static
    {
        return $this->withAccessToken($this->adminToken);
    }
}
