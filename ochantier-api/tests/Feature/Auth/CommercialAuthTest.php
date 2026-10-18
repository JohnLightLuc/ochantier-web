<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\CommercialProfile;
use App\Models\LoginAttempt;
use App\Models\User;

class CommercialAuthTest extends AuthTestCase
{
    public function test_a_commercial_signs_in_with_password_then_sms_code(): void
    {
        $user = User::factory()->commercial()->create(['phone' => '+2250102030405']);

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '01 02 03 04 05', 'password' => 'motdepasse1'])
            ->assertAccepted()
            ->assertJsonPath('destination', '+225 01 •• •• •• 05');

        $this->assertSame('+225 01 02 03 04 05', $this->sms->sent[0]['phone']);

        $this->postJson('/api/v1/auth/commercial/login/verify', ['phone' => '0102030405', 'code' => '123456', ...$this->device('Samsung A54')])
            ->assertOk()
            ->assertJsonPath('user.commercial.employee_number', $user->commercialProfile->employee_number)
            ->assertJsonPath('user.must_change_password', false);

        $this->assertTrue(LoginAttempt::query()->where('success', true)->exists());
    }

    public function test_a_wrong_password_is_rejected_without_sending_a_code(): void
    {
        User::factory()->commercial()->create(['phone' => '+2250102030405']);

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'mauvais'])
            ->assertJsonValidationErrors(['password' => 'Numéro ou mot de passe incorrect.']);

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0999999999', 'password' => 'motdepasse1'])
            ->assertJsonValidationErrors(['password' => 'Numéro ou mot de passe incorrect.']);

        $this->assertEmpty($this->sms->sent);
    }

    public function test_the_account_is_locked_after_five_wrong_passwords(): void
    {
        $user = User::factory()->commercial()->create(['phone' => '+2250102030405']);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'mauvais'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'mauvais'])
            ->assertStatus(423)
            ->assertJsonStructure(['message', 'locked_until']);

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'motdepasse1'])->assertStatus(423);
        $this->assertTrue($user->fresh()->isLocked());

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'motdepasse1'])->assertAccepted();
        $this->assertSame(0, $user->fresh()->failed_login_count);
    }

    public function test_a_provisional_password_must_be_replaced_before_using_the_app(): void
    {
        $user = User::factory()->commercial('Provisoire1')->provisional()->create(['phone' => '+2250102030405']);

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'Provisoire1'])->assertAccepted();

        $token = $this->postJson('/api/v1/auth/commercial/login/verify', ['phone' => '0102030405', 'code' => '123456', ...$this->device()])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.status.value', 'actif')
            ->json('access_token');

        $this->assertSame(UserStatus::Actif, $user->fresh()->status);

        $this->withAccessToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withAccessToken($token)->getJson('/api/v1/auth/sessions')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->withAccessToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'Provisoire1', 'password' => 'Provisoire1', 'password_confirmation' => 'Provisoire1'])
            ->assertJsonValidationErrors(['password' => 'Choisissez un mot de passe différent de l’actuel.']);

        $this->withAccessToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'Provisoire1', 'password' => 'courte', 'password_confirmation' => 'courte'])
            ->assertJsonValidationErrors(['password']);

        $this->withAccessToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'Provisoire1', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false);

        $this->withAccessToken($token)->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_commercial_whose_contract_ended_cannot_sign_in(): void
    {
        $user = User::factory()->create(['user_type' => 'commercial', 'password_hash' => 'motdepasse1', 'phone' => '+2250102030405']);
        CommercialProfile::factory()->ended()->for($user)->create();

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'motdepasse1'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre accès commercial n’est plus actif. Contactez votre responsable O’CHANTIER.');
    }

    public function test_a_forgotten_password_is_reset_with_an_sms_code_and_closes_every_session(): void
    {
        $user = User::factory()->commercial()->create(['phone' => '+2250102030405']);
        $session = $this->openSession($user)->session;

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0102030405'])->assertAccepted();
        $this->assertStringContainsString('réinitialisation', $this->sms->sent[0]['message']);

        $this->postJson('/api/v1/auth/password/reset', ['phone' => '0102030405', 'code' => '654321', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026'])
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/auth/password/reset', ['phone' => '0102030405', 'code' => '123456', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026'])
            ->assertOk();

        $this->assertNotNull($session->fresh()->revoked_at);

        $this->postJson('/api/v1/auth/commercial/login', ['phone' => '0102030405', 'password' => 'NouveauMdp2026'])->assertAccepted();
    }

    public function test_promoters_cannot_use_the_commercial_password_screens(): void
    {
        $promoter = User::factory()->promoter()->create();

        $this->withAccessToken($this->openSession($promoter)->accessToken)
            ->putJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => 'NouveauMdp2026', 'password_confirmation' => 'NouveauMdp2026'])
            ->assertForbidden();
    }
}
