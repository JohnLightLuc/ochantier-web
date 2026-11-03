<?php

namespace Tests\Feature\Admin;

use App\Enums\DisputeStatus;
use App\Enums\DocumentVerificationStatus;
use App\Enums\PromoterVerificationStatus;
use App\Enums\UserStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Dispute;
use App\Models\Document;
use App\Models\Notification;
use App\Models\PromoterProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Promoteurs : liste et filtres, fiche, contrôle des pièces, décisions sur le dossier,
 * suspension et messages.
 */
class PromoterAdminTest extends PromoterTestCase
{
    private string $adminToken;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAccessSeeder::class);
        $this->admin = Admin::factory()->role('operations')->create(['first_name' => 'Abdoulaye', 'last_name' => 'Koné']);
        $this->adminToken = $this->adminTokenFor($this->admin);
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }

    /**
     * @return $this
     */
    private function asAdmin(?string $token = null): static
    {
        return $this->withAccessToken($token ?? $this->adminToken);
    }

    /**
     * Le promoteur connecté complète son profil de particulier : le dossier est transmis (« Soumis »).
     */
    private function submitIndividual(): PromoterProfile
    {
        $this->actingAsPromoter($this->promoter);
        $this->patchJson('/api/v1/promoter/profile', ['first_name' => 'Nathanaël', 'last_name' => 'Yao', 'commune_id' => $this->commune()])->assertOk();
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_cni', 'recto' => $this->image('recto.jpg'), 'verso' => $this->image('verso.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_residence_certificate', 'file' => $this->pdf('residence.pdf')], ['Accept' => 'application/json'])->assertCreated();

        $profile = $this->promoter->promoterProfile->fresh();
        $this->assertSame(PromoterVerificationStatus::Soumis, $profile->verification_status);

        return $profile;
    }

    /**
     * @return array{identity: string, residence: string}
     */
    private function documentIds(PromoterProfile $profile): array
    {
        $documents = Document::query()->where('owner_type', 'promoter_profile')->where('owner_id', $profile->id)->with('documentType')->get();

        return [
            'identity' => $documents->first(fn (Document $document) => $document->documentType->is_identity)->id,
            'residence' => $documents->first(fn (Document $document) => $document->documentType->code === 'promoter_residence_certificate')->id,
        ];
    }

    public function test_the_list_is_filtered_counted_and_sorted(): void
    {
        $this->submitIndividual();
        $draft = User::factory()->promoter()->create(['first_name' => 'Awa', 'last_name' => 'Bamba', 'dial_code' => '225', 'phone_number' => '0711111111']);
        $suspended = User::factory()->promoter()->create(['first_name' => 'Koffi', 'last_name' => 'Zran', 'status' => UserStatus::Suspendu, 'dial_code' => '225', 'phone_number' => '0722222222']);
        $suspended->promoterProfile->update(['verification_status' => PromoterVerificationStatus::Valide]);

        $response = $this->asAdmin()->getJson('/api/v1/admin/promoters?sort=name')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.counts.total', 3)
            ->assertJsonPath('meta.counts.statuses.soumis', 1)
            ->assertJsonPath('meta.counts.statuses.brouillon', 1)
            ->assertJsonPath('meta.counts.statuses.suspendu', 1)
            ->assertJsonPath('meta.counts.statuses.valide', 0)
            ->assertJsonPath('meta.counts.todo.a_verifier', 1)
            ->assertJsonPath('meta.counts.todo.incomplets', 1)
            ->assertJsonPath('data.*.full_name', ['Awa Bamba', 'Nathanaël Yao', 'Koffi Zran'])
            ->assertJsonPath('data.2.status', ['value' => 'suspendu', 'label' => 'Suspendu'])
            ->assertJsonPath('data.2.verification_status.value', 'valide');

        $this->assertSame('+225 07 11 11 11 11', $response->json('data.0.phone'));

        $this->getJson('/api/v1/admin/promoters?todo=a_verifier')->assertJsonPath('data.*.full_name', ['Nathanaël Yao']);
        $this->getJson('/api/v1/admin/promoters?status=suspendu')->assertJsonPath('data.*.id', [$suspended->promoterProfile->id]);
        $this->getJson('/api/v1/admin/promoters?status=valide')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/promoters?search=bamba')->assertJsonPath('data.*.id', [$draft->promoterProfile->id]);
        $this->getJson('/api/v1/admin/promoters?search=0711111111')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/promoters?status=inconnu')->assertJsonValidationErrors('status');
    }

    public function test_expired_documents_and_open_disputes_are_flagged(): void
    {
        $profile = $this->submitIndividual();
        Document::query()->whereKey($this->documentIds($profile)['identity'])->update(['expires_at' => now()->subDay()->toDateString()]);
        $site = Site::query()->create(['reference' => 'CHT-2026-000001', 'promoter_profile_id' => $profile->id, 'created_by_user_id' => $this->promoter->id, 'label' => 'Villa Angré']);
        Dispute::query()->create(['reference' => 'LIT-00001', 'category' => 'prestation', 'opened_by_type' => 'user', 'opened_by_id' => $this->promoter->id, 'site_id' => $site->id, 'description' => 'Travaux non conformes.', 'status' => DisputeStatus::Ouvert]);

        $this->asAdmin()->getJson('/api/v1/admin/promoters?todo=pieces_expirees')
            ->assertJsonPath('meta.counts.todo.pieces_expirees', 1)
            ->assertJsonPath('meta.counts.todo.signales', 1)
            ->assertJsonPath('data.0.expired_documents_count', 1)
            ->assertJsonPath('data.0.open_disputes_count', 1)
            ->assertJsonPath('data.0.sites_count', 0)
            ->assertJsonPath('meta.communes.0.name', 'Cocody');

        $this->getJson('/api/v1/admin/promoters?todo=signales')->assertJsonPath('meta.total', 1);
    }

    public function test_the_detail_shows_the_profile_documents_and_the_review_in_progress(): void
    {
        $profile = $this->submitIndividual();

        $this->asAdmin()->getJson("/api/v1/admin/promoters/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Nathanaël Yao')
            ->assertJsonPath('data.status.value', 'soumis')
            ->assertJsonPath('data.completion.complete', true)
            ->assertJsonPath('data.documents.slots.0.document.files.0.side', 'recto')
            ->assertJsonPath('data.account.status.value', 'actif')
            ->assertJsonPath('data.review.can_decide', true)
            ->assertJsonPath('data.review.can_approve', false)
            ->assertJsonPath('data.review.approval_refusal', 'Pièces à vérifier avant la validation : Pièce d’identité, Certificat de résidence.')
            ->assertJsonPath('data.review.required_documents.*.key', ['identity', 'residence'])
            ->assertJsonPath('data.review.current.submission_number', 1)
            ->assertJsonPath('data.review.current.status.value', 'soumis');

        $this->getJson('/api/v1/admin/promoters/'.fake()->uuid())->assertNotFound();
    }

    public function test_an_individual_is_approved_once_its_documents_are_verified(): void
    {
        $profile = $this->submitIndividual();
        $ids = $this->documentIds($profile);

        $this->asAdmin()->postJson("/api/v1/admin/promoters/{$profile->id}/approve")
            ->assertJsonValidationErrors(['promoter' => 'Pièces à vérifier avant la validation : Pièce d’identité, Certificat de résidence.']);

        $this->postJson("/api/v1/admin/promoters/{$profile->id}/documents/{$ids['identity']}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Pièce vérifiée.')
            ->assertJsonPath('data.status.value', 'en_controle')
            ->assertJsonPath('data.review.current.assigned_admin.full_name', 'Abdoulaye Koné');
        $this->postJson("/api/v1/admin/promoters/{$profile->id}/documents/{$ids['residence']}/approve")->assertOk();

        $this->postJson("/api/v1/admin/promoters/{$profile->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Compte promoteur validé.')
            ->assertJsonPath('data.status.value', 'valide')
            ->assertJsonPath('data.verification.state.value', 'verifie')
            ->assertJsonPath('data.account.validated_by.full_name', 'Abdoulaye Koné')
            ->assertJsonPath('data.review.current.status.value', 'valide')
            ->assertJsonPath('data.review.can_decide', false);

        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'account.approved')->exists());
        $this->assertSame(['document.approved', 'document.approved', 'promoter.approved'], AuditLog::query()->where('actor_id', $this->admin->id)->orderBy('created_at')->orderBy('id')->pluck('action')->filter(fn ($a) => $a !== 'login')->values()->all());

        $this->postJson("/api/v1/admin/promoters/{$profile->id}/approve")
            ->assertJsonValidationErrors(['promoter' => 'Le dossier n’est pas en attente de vérification (statut : Validé).']);
    }

    public function test_corrections_reject_the_documents_and_the_replacement_sends_the_file_back(): void
    {
        $profile = $this->submitIndividual();
        $ids = $this->documentIds($profile);

        $this->asAdmin()->postJson("/api/v1/admin/promoters/{$profile->id}/corrections", ['corrections' => [['reason' => 'Non']]])
            ->assertJsonValidationErrors(['corrections.0.document_id', 'corrections.0.reason']);

        $this->postJson("/api/v1/admin/promoters/{$profile->id}/corrections", [
            'corrections' => [['document_id' => $ids['residence'], 'reason' => 'Certificat daté de plus de 3 mois.']],
            'message' => 'Merci de fournir un certificat récent.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'a_corriger')
            ->assertJsonPath('data.review.current.status.value', 'a_corriger')
            ->assertJsonPath('data.review.current.decision_reason', 'Merci de fournir un certificat récent.')
            ->assertJsonPath('data.review.current.corrections.0.document.type.code', 'promoter_residence_certificate')
            ->assertJsonPath('data.documents.slots.1.state.value', 'refuse')
            ->assertJsonPath('data.documents.slots.1.document.rejection_reason', 'Certificat daté de plus de 3 mois.');

        $this->assertSame(DocumentVerificationStatus::Rejete, Document::query()->find($ids['residence'])->verification_status);
        $this->assertSame(1, count($this->sms->sent));

        $this->actingAsPromoter($this->promoter);
        $this->post('/api/v1/promoter/profile/documents', ['document_type' => 'promoter_residence_certificate', 'file' => $this->pdf('nouveau.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.verification.status.value', 'soumis');

        $this->asAdmin()->getJson("/api/v1/admin/promoters/{$profile->id}")
            ->assertJsonPath('data.review.current.submission_number', 2)
            ->assertJsonPath('data.review.history.0.status.value', 'a_corriger');
    }

    public function test_a_file_is_rejected_with_a_reason(): void
    {
        $profile = $this->submitIndividual();

        $this->asAdmin()->postJson("/api/v1/admin/promoters/{$profile->id}/reject", ['reason' => ''])->assertJsonValidationErrors(['reason' => 'Indiquez le motif.']);

        $this->postJson("/api/v1/admin/promoters/{$profile->id}/reject", ['reason' => 'Pièce d’identité falsifiée.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'refuse')
            ->assertJsonPath('data.review.current.decision_reason', 'Pièce d’identité falsifiée.');

        $this->assertTrue(Notification::query()->where('recipient_id', $this->promoter->id)->where('event_code', 'account.rejected')->where('body', 'Pièce d’identité falsifiée.')->exists());
    }

    public function test_a_suspended_account_is_signed_out_and_reactivated_with_its_previous_status(): void
    {
        $profile = $this->submitIndividual();
        $this->assertTrue(AuthSession::query()->where('authenticatable_id', $this->promoter->id)->whereNull('revoked_at')->exists());

        $this->asAdmin()->postJson("/api/v1/admin/promoters/{$profile->id}/suspension", ['reason' => 'Paiements frauduleux signalés.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'suspendu')
            ->assertJsonPath('data.account.suspended_reason', 'Paiements frauduleux signalés.');

        $this->assertFalse(AuthSession::query()->where('authenticatable_id', $this->promoter->id)->whereNull('revoked_at')->exists());
        $this->postJson("/api/v1/admin/promoters/{$profile->id}/suspension", ['reason' => 'Encore une fois.'])->assertJsonValidationErrors(['promoter' => 'Ce compte est déjà suspendu.']);

        $this->deleteJson("/api/v1/admin/promoters/{$profile->id}/suspension")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'soumis')
            ->assertJsonPath('data.account.status.value', 'actif')
            ->assertJsonPath('data.account.suspended_reason', null);
    }

    public function test_bulk_approval_validates_the_ready_files_and_explains_the_others(): void
    {
        $ready = $this->submitIndividual();
        Document::query()->where('owner_id', $ready->id)->update(['verification_status' => DocumentVerificationStatus::Valide]);
        $draft = User::factory()->promoter()->create(['first_name' => 'Awa', 'last_name' => 'Bamba', 'dial_code' => '225', 'phone_number' => '0711111111'])->promoterProfile;

        $this->asAdmin()->postJson('/api/v1/admin/promoters/approve', ['ids' => [$ready->id, $draft->id]])
            ->assertOk()
            ->assertJsonPath('message', '1 compte validé.')
            ->assertJsonPath('data.approved', [$ready->id])
            ->assertJsonPath('data.skipped.0.full_name', 'Awa Bamba')
            ->assertJsonPath('data.skipped.0.reason', 'Le dossier n’est pas en attente de vérification (statut : Brouillon).');
    }

    public function test_a_message_reaches_the_promoter_notification_center(): void
    {
        $profile = $this->submitIndividual();

        $this->asAdmin()->postJson("/api/v1/admin/promoters/{$profile->id}/messages", ['title' => 'Bienvenue', 'body' => 'Votre conseiller vous appellera demain.'])
            ->assertOk()
            ->assertJsonPath('message', 'Message envoyé au promoteur.');

        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/notifications')->assertOk()->assertSee('Votre conseiller vous appellera demain.');
    }

    public function test_the_export_lists_the_filtered_promoters_as_csv(): void
    {
        $this->submitIndividual();

        $this->asAdmin($this->adminTokenFor(Admin::factory()->role('support')->create()))->get('/api/v1/admin/promoters/export')->assertForbidden();

        $csv = $this->asAdmin($this->adminTokenFor(Admin::factory()->role('direction')->create()))->get('/api/v1/admin/promoters/export?status=soumis')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('"Nathanaël Yao";"+225 07 12 34 56 34";;Particulier;;;Cocody;Soumis;0;0;', $lines[1]);
        $this->assertStringContainsString('Soumis', $lines[1]);
    }

    public function test_permissions_are_checked_on_every_route(): void
    {
        $profile = $this->submitIndividual();
        $support = Admin::factory()->role('support')->create();
        $token = $this->adminTokenFor($support);

        $this->asAdmin($token)->getJson('/api/v1/admin/promoters')->assertOk();
        $this->postJson("/api/v1/admin/promoters/{$profile->id}/approve")->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->postJson("/api/v1/admin/promoters/{$profile->id}/suspension", ['reason' => 'Test de droits.'])->assertForbidden();

        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/admin/promoters')->assertUnauthorized();
    }
}
