<?php

namespace Tests\Feature\Admin;

use App\Enums\CommercialProfileVerificationStatus;
use App\Enums\UserStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\CommercialProfile;
use App\Models\PasswordReset;
use App\Models\User;
use App\Support\Admin\CommercialActivation;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Commerciaux : création, modification, lien d’accès, sessions et pièces.
 */
class CommercialAccountAdminTest extends CommercialTestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $this->admin = Admin::factory()->role('operations')->create();
        Mail::fake();
    }

    private function asAdmin(?Admin $admin = null): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? $this->admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return ['first_name' => 'Fatou', 'last_name' => 'Diallo', 'dial_code' => '225', 'phone_number' => '0500000077', 'email' => 'Fatou@Ochantier.ci', 'address' => 'Angré', 'started_at' => '2026-11-02', 'primary_commune_id' => $this->commune('Cocody'), 'secondary_commune_ids' => [$this->commune('Yopougon')], ...$overrides];
    }

    private function create(array $overrides = []): CommercialProfile
    {
        $id = $this->asAdmin()->postJson('/api/v1/admin/commercials', $this->form($overrides))->assertCreated()->json('data.id');

        return CommercialProfile::query()->findOrFail($id);
    }

    private function tokenFromLastSms(): string
    {
        preg_match('/token=([A-Za-z0-9]+)/', collect($this->sms->sent)->last()['message'], $match);

        return $match[1];
    }

    public function test_a_created_commercial_waits_for_activation_and_receives_a_link(): void
    {
        $response = $this->asAdmin()->postJson('/api/v1/admin/commercials', $this->form())->assertCreated();

        $number = $response->json('data.employee_number');
        $this->assertMatchesRegularExpression('/^COM-\d{5}$/', $number);
        $response->assertJsonPath('data.status.value', 'en_attente_activation')
            ->assertJsonPath('data.email', 'fatou@ochantier.ci')
            ->assertJsonPath('data.zones.0.name', 'Cocody')
            ->assertJsonPath('data.zones.0.is_primary', true)
            ->assertJsonPath('data.zones.1.name', 'Yopougon')
            ->assertJsonPath('data.verification_status.value', 'incomplet')
            ->assertJsonPath('data.access_link.state.value', 'en_cours')
            ->assertJsonPath('data.password_set', false)
            ->assertJsonPath('data.documents.slots.0.state.value', 'manquant')
            ->assertJsonPath('data.can.send_link', true);

        $sent = collect($this->sms->sent)->last();
        $this->assertSame('+2250500000077', $sent['phone']);
        $this->assertStringContainsString('/activation-commercial?token=', $sent['message']);
        $this->assertSame(0, PasswordReset::query()->where('token_hash', $this->tokenFromLastSms())->count());
        $this->assertTrue(AuditLog::query()->where('action', 'commercial.created')->exists());

        $next = $this->postJson('/api/v1/admin/commercials', $this->form(['phone_number' => '0500000078', 'email' => null, 'send_link' => false]))->assertCreated();
        $this->assertSame(sprintf('COM-%05d', (int) substr($number, 4) + 1), $next->json('data.employee_number'));
        $next->assertJsonPath('data.access_link.state.value', 'aucun');
    }

    public function test_the_link_sets_the_password_once_and_the_first_login_activates_the_account(): void
    {
        $commercial = $this->create();
        $token = $this->tokenFromLastSms();

        $this->getJson('/api/v1/auth/commercial/activation?token='.$token)->assertOk()->assertJsonPath('data.first_name', 'Fatou');
        $this->postJson('/api/v1/auth/commercial/activation', ['token' => $token, 'password' => 'court', 'password_confirmation' => 'court'])->assertJsonValidationErrors(['password']);
        $this->postJson('/api/v1/auth/commercial/activation', ['token' => $token, 'password' => 'motdepasse2', 'password_confirmation' => 'motdepasse2'])->assertOk();

        $this->getJson('/api/v1/auth/commercial/activation?token='.$token)->assertJsonValidationErrors(['token']);
        $this->postJson('/api/v1/auth/commercial/activation', ['token' => $token, 'password' => 'motdepasse3', 'password_confirmation' => 'motdepasse3'])->assertJsonValidationErrors(['token']);

        $this->assertSame(UserStatus::EnAttenteActivation, $commercial->user->fresh()->status);
        $this->postJson('/api/v1/auth/commercial/login', ['dial_code' => '225', 'phone_number' => '0500000077', 'password' => 'motdepasse2'])->assertSuccessful();

        $this->asAdmin()->getJson("/api/v1/admin/commercials/{$commercial->id}")->assertJsonPath('data.access_link.state.value', 'utilise')->assertJsonPath('data.password_set', true);
    }

    public function test_a_new_link_replaces_the_previous_one_and_can_be_revoked(): void
    {
        $commercial = $this->create();
        $first = $this->tokenFromLastSms();
        $url = "/api/v1/admin/commercials/{$commercial->id}/access-link";

        $this->postJson($url)->assertOk();
        $second = $this->tokenFromLastSms();
        $this->assertNotSame($first, $second);
        $this->getJson('/api/v1/auth/commercial/activation?token='.$first)->assertJsonValidationErrors(['token']);
        $this->getJson('/api/v1/auth/commercial/activation?token='.$second)->assertOk();

        $this->deleteJson($url)->assertOk()->assertJsonPath('data.access_link.state.value', 'expire');
        $this->getJson('/api/v1/auth/commercial/activation?token='.$second)->assertJsonValidationErrors(['token']);
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors(['commercial']);
    }

    public function test_an_expired_link_no_longer_works(): void
    {
        $this->create();
        $token = $this->tokenFromLastSms();

        $this->travel(CommercialActivation::VALID_HOURS + 1)->hours();

        $this->getJson('/api/v1/auth/commercial/activation?token='.$token)->assertJsonValidationErrors(['token']);
    }

    public function test_a_reset_link_closes_the_sessions_of_an_active_account(): void
    {
        $commercial = $this->commercial->commercialProfile;

        $this->asAdmin()->getJson("/api/v1/admin/commercials/{$commercial->id}/access")->assertOk()->assertJsonPath('data.password_set', true);
        $this->postJson("/api/v1/admin/commercials/{$commercial->id}/access-link")->assertOk();

        $this->assertSame(0, AuthSession::query()->where('authenticatable_id', $this->commercial->id)->whereNull('revoked_at')->count());
        $this->postJson('/api/v1/auth/commercial/activation', ['token' => $this->tokenFromLastSms(), 'password' => 'motdepasse4', 'password_confirmation' => 'motdepasse4'])->assertOk();
        $this->postJson('/api/v1/auth/commercial/login', ['dial_code' => '225', 'phone_number' => '0500000001', 'password' => 'motdepasse4'])->assertSuccessful();
    }

    public function test_sessions_can_be_closed_one_by_one_or_all_together(): void
    {
        $this->openSession($this->commercial);
        $this->openSession($this->commercial);
        $commercial = $this->commercial->commercialProfile;

        $sessions = $this->asAdmin()->getJson("/api/v1/admin/commercials/{$commercial->id}/access")->json('data.sessions');
        $this->assertGreaterThanOrEqual(2, count($sessions));

        $this->deleteJson("/api/v1/admin/commercials/{$commercial->id}/sessions/{$sessions[0]['id']}")->assertOk();
        $this->getJson("/api/v1/admin/commercials/{$commercial->id}/access")->assertJsonCount(count($sessions) - 1, 'data.sessions');
        $this->deleteJson("/api/v1/admin/commercials/{$commercial->id}/sessions/{$sessions[0]['id']}")->assertNotFound();

        $this->deleteJson("/api/v1/admin/commercials/{$commercial->id}/sessions")->assertOk();
        $this->getJson("/api/v1/admin/commercials/{$commercial->id}/access")->assertJsonCount(0, 'data.sessions');
    }

    public function test_a_suspended_or_ended_account_cannot_receive_a_link_and_loses_the_current_one(): void
    {
        $commercial = $this->create();
        $token = $this->tokenFromLastSms();
        $url = "/api/v1/admin/commercials/{$commercial->id}";

        $this->postJson("{$url}/termination", ['reason' => 'Compte créé par erreur.'])->assertOk();
        $this->getJson('/api/v1/auth/commercial/activation?token='.$token)->assertJsonValidationErrors(['token']);
        $this->postJson("{$url}/access-link")->assertUnprocessable()->assertJsonValidationErrors(['commercial']);
        $this->patchJson($url, $this->form(['primary_commune_id' => null, 'secondary_commune_ids' => null]))->assertUnprocessable();

        $this->postJson("{$url}/reactivation")->assertOk()->assertJsonPath('data.status.value', 'en_attente_activation');
        $this->assertSame(UserStatus::EnAttenteActivation, $commercial->user->fresh()->status);
    }

    public function test_an_update_is_audited_and_a_new_phone_closes_the_sessions(): void
    {
        $commercial = $this->commercial->commercialProfile;
        $form = ['first_name' => 'Adama', 'last_name' => 'Traoré', 'dial_code' => '225', 'phone_number' => '0500000055', 'started_at' => '2026-01-05', 'is_supervisor' => true];
        $this->openSession($this->commercial);

        $this->asAdmin()->patchJson("/api/v1/admin/commercials/{$commercial->id}", $form)->assertOk()->assertJsonPath('data.last_name', 'Traoré')->assertJsonPath('data.is_supervisor', true);

        $audit = AuditLog::query()->where('action', 'commercial.updated')->sole();
        $this->assertSame('0500000055', $audit->after_values['phone_number']);
        $this->assertSame(0, AuthSession::query()->where('authenticatable_id', $this->commercial->id)->whereNull('revoked_at')->count());
        $this->assertNull($this->commercial->fresh()->phone_verified_at);

        $this->patchJson("/api/v1/admin/commercials/{$commercial->id}", [...$form, 'primary_commune_id' => $this->commune()])->assertJsonValidationErrors(['primary_commune_id']);
        $this->patchJson("/api/v1/admin/commercials/{$commercial->id}", [...$form, 'supervisor_id' => $commercial->id])->assertJsonValidationErrors(['supervisor_id']);
    }

    public function test_the_phone_and_email_must_be_free_among_commercials(): void
    {
        $other = User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0500000066', 'email' => 'pris@ochantier.ci']);
        $this->assertNotNull($other->id);

        $this->asAdmin()->postJson('/api/v1/admin/commercials', $this->form(['phone_number' => '0500000066', 'email' => 'pris@ochantier.ci']))->assertJsonValidationErrors(['phone_number', 'email']);
        $this->postJson('/api/v1/admin/commercials', $this->form(['primary_commune_id' => null]))->assertJsonValidationErrors(['primary_commune_id']);
    }

    public function test_the_verification_status_follows_the_required_documents(): void
    {
        $commercial = $this->commercial->commercialProfile;
        $commercial->update(['verification_status' => CommercialProfileVerificationStatus::Incomplet]);
        $url = "/api/v1/admin/commercials/{$commercial->id}";
        $this->asAdmin();

        $this->post("{$url}/documents", ['document_type' => 'commercial_cni', 'number' => 'CI123456', 'recto' => UploadedFile::fake()->image('recto.jpg'), 'verso' => UploadedFile::fake()->image('verso.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.verification_status.value', 'incomplet');
        $response = $this->post("{$url}/documents", ['document_type' => 'commercial_contract', 'file' => UploadedFile::fake()->create('contrat.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.verification_status.value', 'soumis');

        $slots = collect($response->json('data.documents.slots'))->keyBy('key');
        $identity = $slots['commercial_cni']['document']['id'];
        $contract = $slots['commercial_contract']['document']['id'];

        $this->postJson("{$url}/documents/{$identity}/approve")->assertOk()->assertJsonPath('data.verification_status.value', 'soumis');
        $this->postJson("{$url}/documents/{$identity}/approve")->assertUnprocessable()->assertJsonValidationErrors(['commercial']);
        $this->postJson("{$url}/documents/{$contract}/reject", ['reason' => 'Contrat non signé.'])->assertOk()->assertJsonPath('data.verification_status.value', 'a_corriger');

        $replacement = $this->post("{$url}/documents", ['document_type' => 'commercial_contract', 'file' => UploadedFile::fake()->create('signe.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.verification_status.value', 'soumis');
        $new = collect($replacement->json('data.documents.slots'))->firstWhere('key', 'commercial_contract')['document']['id'];
        $this->postJson("{$url}/documents/{$new}/approve")->assertOk()->assertJsonPath('data.verification_status.value', 'valide');
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $commercial = $this->commercial->commercialProfile;
        $reader = Admin::factory()->role('finance')->create();

        $this->asAdmin($reader);
        $this->postJson('/api/v1/admin/commercials', $this->form())->assertForbidden();
        $this->patchJson("/api/v1/admin/commercials/{$commercial->id}", [])->assertForbidden();
        $this->postJson("/api/v1/admin/commercials/{$commercial->id}/access-link")->assertForbidden();
        $this->deleteJson("/api/v1/admin/commercials/{$commercial->id}/sessions")->assertForbidden();
        $this->getJson('/api/v1/admin/commercials/form-options')->assertOk()->assertJsonFragment(['code' => 'commercial_cni']);
    }
}
