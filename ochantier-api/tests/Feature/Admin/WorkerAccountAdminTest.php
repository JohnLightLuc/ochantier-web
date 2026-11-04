<?php

namespace Tests\Feature\Admin;

use App\Enums\WorkerVerificationStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommercialEarning;
use App\Models\Notification;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Ouvriers : création et modification d’un ouvrier, ajout de pièces, listes du formulaire.
 */
class WorkerAccountAdminTest extends CommercialTestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->admin = Admin::factory()->role('operations')->create(['first_name' => 'Awa', 'last_name' => 'Koné']);
        $this->withAccessToken(app(AdminSessions::class)->open($this->admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'commercial_id' => $this->commercial->commercialProfile->id,
            'first_name' => 'Ibrahim',
            'last_name' => 'Ouattara',
            'dial_code' => '225',
            'phone_number' => '07 08 09 10 11',
            'gender' => 'M',
            'birth_date' => '1992-03-14',
            'residence_commune_id' => $this->commune(),
            'residence_address' => 'Angré 8e tranche',
            'emergency_contact_name' => 'Fatou Ouattara',
            'emergency_contact_relation' => 'Sœur',
            'emergency_contact_dial_code' => '225',
            'emergency_contact_phone_number' => '0102030405',
            'trade_id' => $this->trade('macon')->id,
            'experience_years' => 8,
            'secondary_trade_id' => $this->trade('peintre')->id,
            'secondary_experience_years' => 2,
            'daily_rate' => 15000,
            'references' => [['project' => 'Résidence Les Palmiers', 'role' => 'Maçon', 'employer' => 'Bâtir Afrique', 'start_date' => '2024-01-10', 'end_date' => '2024-06-30']],
            'works_solo' => true,
            'works_in_team' => true,
            'accepted_offer_types' => ['ponctuelle', 'temporaire'],
            ...$overrides,
        ];
    }

    private function upload(string $worker, array $data): TestResponse
    {
        return $this->post("/api/v1/admin/workers/{$worker}/documents", $data, ['Accept' => 'application/json']);
    }

    public function test_an_admin_registers_a_worker_whose_dossier_goes_to_the_usual_control_without_commission(): void
    {
        $id = $this->postJson('/api/v1/admin/workers', $this->form())
            ->assertCreated()
            ->assertJsonPath('message', 'Ouvrier enregistré : ajoutez les pièces manquantes pour transmettre le dossier.')
            ->assertJsonPath('data.status.value', 'brouillon')
            ->assertJsonPath('data.trade.code', 'macon')
            ->assertJsonPath('data.level.value', 'confirme')
            ->assertJsonPath('data.trades.1.trade.code', 'peintre')
            ->assertJsonPath('data.recruitment.acquisition_channel', 'back_office')
            ->assertJsonPath('data.missing', ['Pièce d’identité', 'Photo d’identité'])
            ->json('data.id');

        $this->getJson('/api/v1/admin/workers?status=brouillon')->assertJsonPath('meta.total', 1);

        $this->upload($id, ['document_type' => 'worker_cni', 'number' => 'CI0098765432', 'recto' => UploadedFile::fake()->image('recto.jpg'), 'verso' => UploadedFile::fake()->image('verso.jpg')])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'brouillon');
        $this->upload($id, ['document_type' => 'worker_photo', 'file' => UploadedFile::fake()->image('photo.jpg', 400, 400)])
            ->assertCreated()
            ->assertJsonPath('message', 'Photo d’identité ajoutée au dossier.')
            ->assertJsonPath('data.status.value', 'soumis')
            ->assertJsonPath('data.review.current.submission_number', 1);

        $worker = Worker::query()->findOrFail($id);
        $this->assertNotNull($worker->photo_media_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'worker.created')->where('subject_id', $id)->count());

        foreach (collect($this->getJson("/api/v1/admin/workers/{$id}")->json('data.documents.slots'))->pluck('document.id')->filter() as $document) {
            $this->postJson("/api/v1/admin/workers/{$id}/documents/{$document}/approve")->assertOk();
        }

        $this->postJson("/api/v1/admin/workers/{$id}/approve")->assertOk()->assertJsonPath('data.status.value', 'valide');
        $this->assertSame(0, CommercialEarning::query()->count());
    }

    public function test_the_form_checks_the_phone_the_commercial_and_the_trades(): void
    {
        $this->postJson('/api/v1/admin/workers', $this->form())->assertCreated();

        $this->postJson('/api/v1/admin/workers', $this->form())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number' => 'Ce numéro est déjà inscrit sur O’CHANTIER.']);

        $this->postJson('/api/v1/admin/workers', $this->form(['phone_number' => '0711111111', 'emergency_contact_phone_number' => '0711111111', 'commercial_id' => fake()->uuid(), 'secondary_trade_id' => $this->trade('macon')->id, 'first_name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['emergency_contact_phone_number', 'commercial_id', 'secondary_trade_id', 'first_name']);

        $this->withAccessToken(app(AdminSessions::class)->open(Admin::factory()->role('support')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken)
            ->postJson('/api/v1/admin/workers', $this->form(['phone_number' => '0722222222']))
            ->assertForbidden();
    }

    public function test_editing_a_validated_worker_keeps_it_validated_and_notifies_the_commercial(): void
    {
        $this->actingAsCommercial($this->commercial);
        $id = $this->readyProspect(['phone_number' => '0700000099']);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();
        $worker = Worker::query()->where('prospect_id', $id)->sole();
        $worker->forceFill(['verification_status' => WorkerVerificationStatus::Valide])->save();

        $this->withAccessToken(app(AdminSessions::class)->open($this->admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
        $before = $this->getJson("/api/v1/admin/workers/{$worker->id}")->json('data');

        $this->patchJson("/api/v1/admin/workers/{$worker->id}", $this->form([
            'first_name' => $before['first_name'],
            'last_name' => $before['last_name'],
            'phone_number' => $before['contact']['phone_number'],
            'emergency_contact_phone_number' => '0102030405',
            'daily_rate' => 18000,
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Ouvrier mis à jour.')
            ->assertJsonPath('data.status.value', 'valide')
            ->assertJsonPath('data.daily_rate', 18000);

        $audit = AuditLog::query()->where('action', 'worker.updated')->sole();
        $this->assertSame(18000, $audit->after_values['estimated_daily_rate']);
        $this->assertStringContainsString('coût journalier', Notification::query()->where('recipient_id', $this->commercial->id)->where('event_code', 'worker.updated')->value('body'));

        $worker->forceFill(['verification_status' => WorkerVerificationStatus::Exclu])->save();
        $this->patchJson("/api/v1/admin/workers/{$worker->id}", $this->form(['phone_number' => $before['contact']['phone_number']]))
            ->assertJsonValidationErrors(['worker' => 'Cet ouvrier est exclu : sa fiche ne peut plus être modifiée.']);
    }

    public function test_the_form_options_list_the_commercials_trades_places_and_document_types(): void
    {
        $this->getJson('/api/v1/admin/workers/form-options')
            ->assertOk()
            ->assertJsonPath('data.commercials.0.id', $this->commercial->commercialProfile->id)
            ->assertJsonPath('data.cities.0.name', 'Abidjan')
            ->assertJsonFragment(['code' => 'worker_photo'])
            ->assertJsonFragment(['code' => 'macon']);
    }
}
