<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\AuthSession;
use App\Models\Contractor;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class ContractorAuthTest extends AuthTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return [
            'dial_code' => '225',
            'phone_number' => '07 00 00 00 01',
            'password' => 'chantier2026',
            'password_confirmation' => 'chantier2026',
            'accept_terms' => true,
            'accept_data_processing' => true,
            ...$overrides,
        ];
    }

    public function test_a_contractor_registers_with_a_phone_number_and_activates_the_account_with_the_sms_code(): void
    {
        $this->postJson('/api/v1/auth/contractor/register', $this->registration())
            ->assertAccepted()
            ->assertJson(['data' => ['channel' => 'sms', 'destination' => '+225 07 •• •• •• 01', 'code_length' => 6]]);

        $user = User::sole();
        $this->assertSame(UserType::Contractor, $user->user_type);
        $this->assertSame(UserStatus::EnAttenteActivation, $user->status);
        $this->assertTrue(Hash::check('chantier2026', $user->password_hash));
        $this->assertStringContainsString('123456', $this->sms->sent[0]['message']);

        $this->postJson('/api/v1/auth/contractor/register/verify', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '123456', ...$this->device()])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user' => ['id', 'contractor']]])
            ->assertJsonPath('data.user.type.value', 'contractor')
            ->assertJsonPath('data.user.status.value', 'actif')
            ->assertJsonPath('data.user.contractor.profile_chosen', false)
            ->assertJsonPath('data.user.contractor.profile', null);

        $user->refresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(['cgu', 'data_processing', 'privacy'], $user->consents()->pluck('consent_type')->map->value->sort()->values()->all());
        $this->assertSame('entrepreneur', AuthSession::sole()->app_name);
    }

    public function test_a_contractor_registers_by_email(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/contractor/register', $this->registration(['dial_code' => null, 'phone_number' => null, 'email' => 'Contact@Lumiere-BTP.ci']))
            ->assertAccepted()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.destination', 'c•••@lumiere-btp.ci');

        $this->postJson('/api/v1/auth/contractor/register/verify', ['email' => 'contact@lumiere-btp.ci', 'code' => '123456', ...$this->device()])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'contact@lumiere-btp.ci')
            ->assertJsonPath('data.user.email_verified', true);
    }

    public function test_registration_checks_the_password_and_the_consents(): void
    {
        $this->postJson('/api/v1/auth/contractor/register', $this->registration(['password' => 'chantier', 'password_confirmation' => 'chantier', 'accept_terms' => false, 'accept_data_processing' => false]))
            ->assertJsonValidationErrors([
                'password' => 'Le mot de passe doit contenir au moins un chiffre.',
                'accept_terms' => 'Acceptez les conditions générales d’utilisation.',
                'accept_data_processing' => 'Acceptez le traitement de vos données personnelles pour la vérification de votre profil.',
            ]);

        $this->postJson('/api/v1/auth/contractor/register', $this->registration(['password_confirmation' => 'autre2026']))
            ->assertJsonValidationErrors(['password' => 'Les deux mots de passe ne correspondent pas.']);

        $this->assertSame(0, User::count());
        $this->assertEmpty($this->sms->sent);
    }

    public function test_an_unfinished_registration_can_be_resumed_with_a_new_password(): void
    {
        $this->postJson('/api/v1/auth/contractor/register', $this->registration())->assertAccepted();
        $this->travel(46)->seconds();
        $this->postJson('/api/v1/auth/contractor/register', $this->registration(['password' => 'nouveau2026', 'password_confirmation' => 'nouveau2026']))->assertAccepted();

        $this->assertTrue(Hash::check('nouveau2026', User::sole()->password_hash));
        $this->assertCount(2, $this->sms->sent);
    }

    public function test_an_active_account_cannot_register_again_but_a_promoter_can_use_the_same_number(): void
    {
        User::factory()->contractor()->create(['dial_code' => '225', 'phone_number' => '0700000001']);

        $this->postJson('/api/v1/auth/contractor/register', $this->registration())
            ->assertJsonValidationErrors(['phone_number' => 'Un compte existe déjà avec ces coordonnées. Connectez-vous.']);

        User::query()->delete();
        User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0700000001']);

        $this->postJson('/api/v1/auth/contractor/register', $this->registration())->assertAccepted();
        $this->assertSame(2, User::count());
    }

    public function test_a_wrong_activation_code_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/contractor/register', $this->registration())->assertAccepted();

        $this->postJson('/api/v1/auth/contractor/register/verify', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '000000', ...$this->device()])
            ->assertJsonValidationErrors(['code' => 'Code incorrect ou expiré.']);

        $this->assertSame(UserStatus::EnAttenteActivation, User::sole()->status);
    }

    public function test_a_contractor_signs_in_with_a_phone_number_or_an_email_and_a_password(): void
    {
        $user = User::factory()->contractor()->create(['dial_code' => '225', 'phone_number' => '0700000001', 'email' => 'serge@example.ci']);
        Contractor::query()->create(['reference' => 'ENT-000001', 'kind' => 'independant', 'name' => 'Yao Serge Koffi', 'user_id' => $user->id]);

        $this->postJson('/api/v1/auth/contractor/login', ['dial_code' => '225', 'phone_number' => '07 00 00 00 01', 'password' => 'motdepasse1', ...$this->device()])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']])
            ->assertJsonPath('data.user.contractor.profile_chosen', true)
            ->assertJsonPath('data.user.contractor.profile.kind.value', 'independant');

        $this->postJson('/api/v1/auth/contractor/login', ['email' => 'SERGE@example.ci', 'password' => 'motdepasse1', ...$this->device('Pixel 8')])->assertOk();

        $this->assertEmpty($this->sms->sent);
        $this->assertSame(2, AuthSession::count());
        $this->assertSame(2, LoginAttempt::query()->where('success', true)->count());
    }

    public function test_a_wrong_password_is_rejected_and_the_account_is_locked_after_five_attempts(): void
    {
        $user = User::factory()->contractor()->create(['dial_code' => '225', 'phone_number' => '0700000001']);
        $login = ['dial_code' => '225', 'phone_number' => '0700000001', 'password' => 'mauvais', ...$this->device()];

        $this->postJson('/api/v1/auth/contractor/login', [...$login, 'phone_number' => '0799999999'])
            ->assertJsonValidationErrors(['password' => 'Identifiant ou mot de passe incorrect.']);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/v1/auth/contractor/login', $login)->assertJsonValidationErrors(['password' => 'Identifiant ou mot de passe incorrect.']);
        }

        $this->postJson('/api/v1/auth/contractor/login', $login)->assertStatus(423)->assertJsonStructure(['locked_until']);
        $this->postJson('/api/v1/auth/contractor/login', [...$login, 'password' => 'motdepasse1'])->assertStatus(423);
        $this->assertTrue($user->refresh()->isLocked());
    }

    public function test_an_unfinished_registration_receives_a_new_activation_code_at_sign_in(): void
    {
        User::factory()->contractor()->create(['dial_code' => '225', 'phone_number' => '0700000001', 'status' => UserStatus::EnAttenteActivation, 'phone_verified_at' => null]);

        $this->postJson('/api/v1/auth/contractor/login', ['dial_code' => '225', 'phone_number' => '0700000001', 'password' => 'motdepasse1', ...$this->device()])
            ->assertAccepted()
            ->assertJsonPath('data.activation_required', true)
            ->assertJsonMissingPath('data.access_token');

        $this->postJson('/api/v1/auth/contractor/register/verify', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '123456', ...$this->device()])->assertCreated();
    }

    public function test_a_suspended_contractor_or_another_application_account_cannot_sign_in(): void
    {
        User::factory()->contractor()->suspended()->create(['dial_code' => '225', 'phone_number' => '0700000001']);
        User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0700000002']);

        $this->postJson('/api/v1/auth/contractor/login', ['dial_code' => '225', 'phone_number' => '0700000001', 'password' => 'motdepasse1', ...$this->device()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre compte est suspendu. Contactez le support O’CHANTIER.');

        $this->postJson('/api/v1/auth/contractor/login', ['dial_code' => '225', 'phone_number' => '0700000002', 'password' => 'motdepasse1', ...$this->device()])
            ->assertJsonValidationErrors(['password' => 'Identifiant ou mot de passe incorrect.']);
    }

    public function test_a_forgotten_password_is_reset_with_the_code_and_every_session_is_closed(): void
    {
        $user = User::factory()->contractor()->create(['dial_code' => '225', 'phone_number' => '0700000001']);
        $tokens = $this->openSession($user);

        $this->postJson('/api/v1/auth/contractor/password/forgot', ['dial_code' => '225', 'phone_number' => '0700000001'])->assertAccepted();
        $this->assertCount(1, $this->sms->sent);

        $this->postJson('/api/v1/auth/contractor/password/verify', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '000000'])
            ->assertJsonValidationErrors(['code' => 'Code incorrect ou expiré.']);
        $this->postJson('/api/v1/auth/contractor/password/verify', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '123456'])->assertOk();

        $this->postJson('/api/v1/auth/contractor/password/reset', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '123456', 'password' => 'nouveau2026', 'password_confirmation' => 'nouveau2026'])
            ->assertOk();

        $this->assertTrue(Hash::check('nouveau2026', $user->refresh()->password_hash));
        $this->withAccessToken($tokens->accessToken)->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/contractor/password/reset', ['dial_code' => '225', 'phone_number' => '0700000001', 'code' => '123456', 'password' => 'autre2026', 'password_confirmation' => 'autre2026'])
            ->assertJsonValidationErrors(['code' => 'Code incorrect ou expiré.']);
    }

    public function test_a_forgotten_password_does_not_reveal_unknown_accounts(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/contractor/password/forgot', ['email' => 'inconnu@example.ci'])
            ->assertAccepted()
            ->assertJsonPath('message', 'Si un compte correspond à cet identifiant, un code vient de lui être envoyé.');

        Mail::assertNothingOutgoing();
    }

    public function test_a_contractor_changes_the_password_from_the_application(): void
    {
        $user = User::factory()->contractor()->create();

        $this->withAccessToken($this->openSession($user)->accessToken)
            ->putJson('/api/v1/auth/password', ['current_password' => 'motdepasse1', 'password' => 'nouveau2026', 'password_confirmation' => 'nouveau2026'])
            ->assertOk()
            ->assertJsonPath('data.contractor.profile_chosen', false);

        $this->assertTrue(Hash::check('nouveau2026', $user->refresh()->password_hash));
    }

    public function test_a_contractor_cannot_use_the_other_applications(): void
    {
        $user = User::factory()->contractor()->create();

        $this->withAccessToken($this->openSession($user)->accessToken)->getJson('/api/v1/promoter/profile')->assertForbidden();
    }
}
