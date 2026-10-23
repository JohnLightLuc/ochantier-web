<?php

namespace Tests\Feature\Auth;

use App\Enums\OrganizationRole;
use App\Enums\PromoterProfileKind;
use App\Enums\UserStatus;
use App\Models\AuthSession;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class PromoterAuthTest extends AuthTestCase
{
    public function test_an_individual_registers_with_a_phone_number_and_activates_the_account_with_the_sms_code(): void
    {
        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '07 08 09 10 11', 'accept_terms' => true])
            ->assertAccepted()
            ->assertJson(['data' => ['channel' => 'sms', 'destination' => '+225 07 •• •• •• 11', 'resend_in' => 45, 'code_length' => 6]]);

        $this->assertCount(1, $this->sms->sent);
        $this->assertStringContainsString('123456', $this->sms->sent[0]['message']);
        $this->assertSame(UserStatus::EnAttenteActivation, User::sole()->status);

        $this->postJson('/api/v1/auth/promoter/register/verify', ['dial_code' => '225', 'phone_number' => '0708091011', 'code' => '123456', ...$this->device()])
            ->assertCreated()
            ->assertJsonStructure(['message', 'data' => ['access_token', 'refresh_token', 'expires_in', 'refresh_expires_at', 'user' => ['id', 'promoter']]])
            ->assertJsonPath('data.user.status.value', 'actif')
            ->assertJsonPath('data.user.phone', '+225 07 08 09 10 11')
            ->assertJsonPath('data.user.promoter.profile_kind.value', 'particulier');

        $user = User::sole();
        $this->assertSame(UserStatus::Actif, $user->status);
        $this->assertSame('+2250708091011', $user->phone);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(['cgu', 'privacy'], $user->consents()->pluck('consent_type')->map->value->sort()->values()->all());
        $this->assertSame('promoteur', AuthSession::sole()->app_name);
    }

    public function test_a_company_registers_by_email_and_becomes_administrator_of_its_organization(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'entreprise', 'email' => 'Contact@Batir.ci', 'accept_terms' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_name' => 'Indiquez le nom de votre entreprise.']);

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'entreprise', 'company_name' => 'Bâtir CI', 'email' => 'Contact@Batir.ci', 'accept_terms' => true])
            ->assertAccepted()
            ->assertJson(['data' => ['channel' => 'email', 'destination' => 'c•••@batir.ci']]);

        $this->assertSame('email', OtpCode::sole()->channel->value);
        $this->assertEmpty($this->sms->sent);

        $this->postJson('/api/v1/auth/promoter/register/verify', ['email' => 'contact@batir.ci', 'code' => '123456', ...$this->device()])
            ->assertCreated()
            ->assertJsonPath('data.user.promoter.organization.legal_name', 'Bâtir CI');

        $user = User::sole();
        $this->assertNull($user->phone);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(PromoterProfileKind::Entreprise, $user->promoterProfile->profile_kind);
        $this->assertSame(OrganizationRole::Administrateur, $user->ownedOrganizations->sole()->members->sole()->role);
    }

    public function test_registration_requires_the_terms_and_a_contact(): void
    {
        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number', 'email', 'accept_terms']);

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0708', 'accept_terms' => true])
            ->assertJsonValidationErrors(['phone_number' => 'Saisissez un numéro à 10 chiffres.']);
    }

    public function test_an_existing_active_account_cannot_register_again(): void
    {
        User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0708091011']);

        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0708091011', 'accept_terms' => true])
            ->assertJsonValidationErrors(['phone_number' => 'Un compte existe déjà avec ces coordonnées. Connectez-vous.']);
    }

    public function test_a_new_code_can_only_be_requested_after_the_resend_delay(): void
    {
        $payload = ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0708091011', 'accept_terms' => true];

        $this->postJson('/api/v1/auth/promoter/register', $payload)->assertAccepted();
        $this->postJson('/api/v1/auth/promoter/register', $payload)->assertTooManyRequests()->assertJsonStructure(['retry_after']);

        $this->travel(46)->seconds();

        $this->postJson('/api/v1/auth/promoter/register', $payload)->assertAccepted();
        $this->assertCount(2, $this->sms->sent);
        $this->assertSame(1, User::count());
    }

    public function test_the_code_length_is_read_from_the_configuration(): void
    {
        config(['services.otp.length' => 4, 'services.otp.test_code' => null]);
        $payload = ['dial_code' => '225', 'phone_number' => '0708091011'];

        $this->postJson('/api/v1/auth/promoter/register', [...$payload, 'profile_kind' => 'particulier', 'accept_terms' => true])
            ->assertAccepted()
            ->assertJsonPath('data.code_length', 4);

        $this->assertMatchesRegularExpression('/ est \d{4}\. /', $this->sms->sent[0]['message']);

        $this->postJson('/api/v1/auth/promoter/register/verify', [...$payload, 'code' => '123456', ...$this->device()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Saisissez les 4 chiffres du code.']);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0708091011', 'accept_terms' => true]);

        $this->postJson('/api/v1/auth/promoter/register/verify', ['dial_code' => '225', 'phone_number' => '0708091011', 'code' => '000000', ...$this->device()])
            ->assertJsonValidationErrors(['code' => 'Code incorrect ou expiré.']);

        $this->assertSame(1, OtpCode::sole()->attempts);
        $this->assertSame(UserStatus::EnAttenteActivation, User::sole()->status);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/promoter/register', ['profile_kind' => 'particulier', 'dial_code' => '225', 'phone_number' => '0708091011', 'accept_terms' => true]);

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/promoter/register/verify', ['dial_code' => '225', 'phone_number' => '0708091011', 'code' => '123456', ...$this->device()])
            ->assertJsonValidationErrors(['code']);
    }

    public function test_an_active_promoter_signs_in_with_a_code(): void
    {
        $user = User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0708091011']);

        $this->postJson('/api/v1/auth/promoter/login', ['dial_code' => '225', 'phone_number' => '0708091011'])->assertAccepted();
        $this->assertCount(1, $this->sms->sent);

        $this->postJson('/api/v1/auth/promoter/login/verify', ['dial_code' => '225', 'phone_number' => '0708091011', 'code' => '123456', ...$this->device()])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_login_response_does_not_reveal_unknown_numbers(): void
    {
        $this->postJson('/api/v1/auth/promoter/login', ['dial_code' => '225', 'phone_number' => '0708091011'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Si un compte est associé à ces coordonnées, un code vient de lui être envoyé.');

        $this->assertEmpty($this->sms->sent);

        $this->postJson('/api/v1/auth/promoter/login/verify', ['dial_code' => '225', 'phone_number' => '0708091011', 'code' => '123456', ...$this->device()])
            ->assertJsonValidationErrors(['code']);
    }

    public function test_a_suspended_promoter_cannot_sign_in(): void
    {
        User::factory()->promoter()->suspended()->create(['dial_code' => '225', 'phone_number' => '0708091011']);

        $this->postJson('/api/v1/auth/promoter/login', ['dial_code' => '225', 'phone_number' => '0708091011'])->assertAccepted();
        $this->assertEmpty($this->sms->sent);

        $this->postJson('/api/v1/auth/promoter/login/verify', ['dial_code' => '225', 'phone_number' => '0708091011', 'code' => '123456', ...$this->device()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre compte est suspendu. Contactez le support O’CHANTIER.');
    }
}
