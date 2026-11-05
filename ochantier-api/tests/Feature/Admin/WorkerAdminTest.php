<?php

namespace Tests\Feature\Admin;

use App\Enums\CommercialEarningStatus;
use App\Enums\DocumentVerificationStatus;
use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommercialEarning;
use App\Models\Document;
use App\Models\Worker;
use App\Models\WorkerSanction;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Ouvriers : liste, fiche, contrôle des pièces et du dossier transmis par le commercial,
 * suspension (avec date de fin) et exclusion.
 */
class WorkerAdminTest extends CommercialTestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->admin = Admin::factory()->role('operations')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
    }

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? $this->admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function submit(string $phone = '0700000099'): Worker
    {
        $id = $this->readyProspect(['phone_number' => $phone]);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();

        return Worker::query()->where('prospect_id', $id)->sole();
    }

    private function fiche(Worker $worker): TestResponse
    {
        return $this->getJson("/api/v1/admin/workers/{$worker->id}")->assertOk();
    }

    /**
     * @return array{identity: string, photo: string}
     */
    private function documents(Worker $worker): array
    {
        $slots = collect($this->fiche($worker)->json('data.documents.slots'))->keyBy('key');

        return ['identity' => $slots['identity']['document']['id'], 'photo' => $slots['worker_photo']['document']['id']];
    }

    private function approveDocuments(Worker $worker): void
    {
        foreach ($this->documents($worker) as $document) {
            $this->postJson("/api/v1/admin/workers/{$worker->id}/documents/{$document}/approve")->assertOk();
        }
    }

    public function test_submitted_dossiers_are_listed_and_the_fiche_shows_what_blocks_the_validation(): void
    {
        $worker = $this->submit();
        $draft = $this->readyProspect(['phone_number' => '0700000098']);

        $this->asAdmin()->getJson('/api/v1/admin/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.statuses.soumis', 1)
            ->assertJsonPath('meta.counts.todo.a_verifier', 1)
            ->assertJsonPath('meta.trades.0.label', 'Maçon')
            ->assertJsonPath('data.0.full_name', 'Jean Kouassi')
            ->assertJsonPath('data.0.trade.code', 'macon')
            ->assertJsonPath('data.0.status.value', 'soumis')
            ->assertJsonPath('data.0.commune.name', 'Cocody');

        $this->getJson('/api/v1/admin/workers?search=kouassi&todo=a_verifier')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/workers?status=valide')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/workers?trade_id='.$this->trade('peintre')->id)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/workers?status=brouillon')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/workers?status=inconnu')->assertJsonValidationErrors(['status']);

        $this->fiche($worker)
            ->assertJsonPath('data.reference', $worker->reference)
            ->assertJsonPath('data.recruitment.current_commercial.full_name', trim($this->commercial->first_name.' '.$this->commercial->last_name))
            ->assertJsonPath('data.documents.slots.0.key', 'identity')
            ->assertJsonPath('data.documents.slots.0.state.value', 'en_attente')
            ->assertJsonPath('data.review.can_decide', true)
            ->assertJsonPath('data.review.can_approve', false)
            ->assertJsonPath('data.review.approval_refusal', 'Pièces à vérifier avant la validation : Pièce d’identité, Photo d’identité.')
            ->assertJsonPath('data.review.current.submission_number', 1);

        $this->assertNotNull($draftWorker = Worker::query()->where('prospect_id', $draft)->first());
        $this->getJson("/api/v1/admin/workers/{$draftWorker->id}")->assertNotFound();

        $csv = $this->get('/api/v1/admin/workers/export?status=soumis')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString($worker->reference.';"Jean Kouassi"', $csv);
    }

    public function test_documents_are_checked_then_the_dossier_is_validated_and_the_commission_confirmed(): void
    {
        $worker = $this->submit();
        $this->asAdmin()->postJson("/api/v1/admin/workers/{$worker->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['worker' => 'Pièces à vérifier avant la validation : Pièce d’identité, Photo d’identité.']);

        $this->approveDocuments($worker);
        $this->assertSame(WorkerVerificationStatus::EnControle, $worker->fresh()->verification_status);

        $this->postJson("/api/v1/admin/workers/{$worker->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Dossier validé : l’ouvrier peut être proposé sur les chantiers.')
            ->assertJsonPath('data.status.value', 'valide')
            ->assertJsonPath('data.validated_by.full_name', 'Awa Koné')
            ->assertJsonPath('data.review.current.decided_by.full_name', 'Awa Koné')
            ->assertJsonPath('data.can.suspend', true);

        $this->assertSame(CommercialEarningStatus::Acquis, CommercialEarning::query()->sole()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'worker.approved')->where('subject_id', $worker->id)->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'document.approved')->count());
        $this->assertSame(['soumis', 'en_controle', 'valide'], $worker->statusHistories()->orderBy('created_at')->orderBy('id')->pluck('to_status')->map->value->all());

        $this->postJson("/api/v1/admin/workers/{$worker->id}/approve")->assertJsonValidationErrors(['worker' => 'Le dossier n’est pas en attente de vérification (statut : Validé).']);
    }

    public function test_corrections_send_the_dossier_back_to_the_commercial_and_a_refusal_cancels_the_commission(): void
    {
        $worker = $this->submit();
        $other = $this->submit('0700000097');
        $identity = $this->asAdmin()->documents($worker)['identity'];

        $this->postJson("/api/v1/admin/workers/{$worker->id}/corrections", ['corrections' => [['reason' => 'Trop court']]])
            ->assertJsonValidationErrors(['corrections.0.document_id', 'corrections.0.field']);

        $this->postJson("/api/v1/admin/workers/{$worker->id}/corrections", [
            'corrections' => [
                ['document_id' => $identity, 'reason' => 'Pièce d’identité illisible, à reprendre.'],
                ['field' => 'daily_rate', 'reason' => 'Le coût journalier semble trop élevé.'],
            ],
            'message' => 'Merci de reprendre le dossier.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'a_corriger')
            ->assertJsonCount(2, 'data.review.current.corrections');

        $this->assertSame(DocumentVerificationStatus::Rejete, Document::query()->find($identity)->verification_status);
        $this->actingAsCommercial($this->commercial)->getJson('/api/v1/commercial/workers?filter=corriger')->assertJsonPath('data.0.can_correct', true);

        $this->asAdmin()->postJson("/api/v1/admin/workers/{$other->id}/reject", ['reason' => 'Identité non vérifiable.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'refuse');
        $this->assertSame(CommercialEarningStatus::Annule, CommercialEarning::query()->where('worker_id', $other->id)->sole()->status);

        $this->postJson('/api/v1/admin/workers/approve', ['ids' => [$worker->id, $other->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Aucun dossier n’a pu être validé.')
            ->assertJsonCount(2, 'data.skipped');
    }

    public function test_a_suspension_ends_at_its_date_and_an_exclusion_is_final(): void
    {
        $worker = $this->submit();
        $this->asAdmin()->approveDocuments($worker);
        $this->postJson("/api/v1/admin/workers/{$worker->id}/approve")->assertOk();

        $this->postJson("/api/v1/admin/workers/{$worker->id}/suspension", ['reason' => 'Absences répétées.', 'until' => today()->toDateString()])
            ->assertJsonValidationErrors(['until' => 'La date de fin doit être postérieure à aujourd’hui.']);
        $this->postJson("/api/v1/admin/workers/{$worker->id}/suspension", ['reason' => 'Absences répétées.', 'until' => today()->addDays(3)->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'suspendu')
            ->assertJsonPath('data.sanction.type.value', 'suspension')
            ->assertJsonPath('data.sanction.reason', 'Absences répétées.')
            ->assertJsonPath('data.can.reactivate', true);
        $this->postJson("/api/v1/admin/workers/{$worker->id}/suspension", ['reason' => 'Absences répétées.'])
            ->assertJsonValidationErrors(['worker' => 'Seul un ouvrier validé peut être suspendu (statut : Suspendu).']);

        $this->artisan('ochantier:lift-worker-suspensions')->expectsOutput('0 suspension levée.')->assertSuccessful();
        $this->travel(4)->days();
        $this->artisan('ochantier:lift-worker-suspensions')->expectsOutput('1 suspension levée.')->assertSuccessful();
        $this->assertSame(WorkerVerificationStatus::Valide, $worker->fresh()->verification_status);
        $this->assertNotNull(WorkerSanction::query()->sole()->lifted_at);

        $this->asAdmin()->postJson("/api/v1/admin/workers/{$worker->id}/suspension", ['reason' => 'Litige en cours d’examen.'])->assertOk()->assertJsonPath('data.sanction.ends_at', null);
        $this->deleteJson("/api/v1/admin/workers/{$worker->id}/suspension")->assertOk()->assertJsonPath('data.status.value', 'valide')->assertJsonPath('data.sanction', null);
        $this->deleteJson("/api/v1/admin/workers/{$worker->id}/suspension")->assertJsonValidationErrors(['worker' => 'Cet ouvrier n’est pas suspendu.']);

        $this->postJson("/api/v1/admin/workers/{$worker->id}/exclusion", ['reason' => 'Fraude avérée sur les pointages.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'exclu')
            ->assertJsonPath('data.sanction.type.value', 'exclusion')
            ->assertJsonPath('data.can.exclude', false);
        $this->postJson("/api/v1/admin/workers/{$worker->id}/exclusion", ['reason' => 'Fraude avérée sur les pointages.'])->assertJsonValidationErrors(['worker']);
        $this->assertSame(['worker.approved', 'worker.suspended', 'worker.suspended', 'worker.reactivated', 'worker.excluded'], AuditLog::query()->where('module', 'workers')->orderBy('created_at')->orderBy('id')->pluck('action')->all());
    }

    public function test_decisions_need_their_permissions(): void
    {
        $worker = $this->submit();

        $this->asAdmin(Admin::factory()->create())->getJson('/api/v1/admin/workers')->assertForbidden();
        $this->asAdmin(Admin::factory()->role('support')->create())->getJson("/api/v1/admin/workers/{$worker->id}")->assertOk();
        $this->postJson("/api/v1/admin/workers/{$worker->id}/approve")->assertForbidden()->assertJsonPath('code', 'permission_denied');
        $this->postJson("/api/v1/admin/workers/{$worker->id}/exclusion", ['reason' => 'Test de droits.'])->assertForbidden();

        $this->asAdmin()->get('/api/v1/admin/workers/'.fake()->uuid())->assertNotFound();
    }

    public function test_the_availability_filter_only_lists_validated_workers(): void
    {
        $validated = Worker::query()->create(['first_name' => 'Valide', 'last_name' => 'Test', 'dial_code' => '225', 'phone_number' => '0700000061', 'verification_status' => WorkerVerificationStatus::Valide, 'availability_status' => 'disponible', 'origin_commercial_id' => $this->commercial->commercialProfile->id, 'current_commercial_id' => $this->commercial->commercialProfile->id, 'reference' => 'OUV-900001']);
        Worker::query()->create(['first_name' => 'Brouillon', 'last_name' => 'Test', 'dial_code' => '225', 'phone_number' => '0700000062', 'verification_status' => WorkerVerificationStatus::Brouillon, 'availability_status' => 'disponible', 'acquisition_channel' => Worker::BACK_OFFICE_CHANNEL, 'origin_commercial_id' => $this->commercial->commercialProfile->id, 'current_commercial_id' => $this->commercial->commercialProfile->id, 'reference' => 'OUV-900002']);

        $this->asAdmin()->getJson('/api/v1/admin/workers?availability=disponible')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $validated->id);
    }
}
