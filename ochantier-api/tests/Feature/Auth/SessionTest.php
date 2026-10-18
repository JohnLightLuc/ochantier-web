<?php

namespace Tests\Feature\Auth;

use App\Models\DeviceToken;
use App\Models\User;
use Firebase\JWT\JWT;

class SessionTest extends AuthTestCase
{
    public function test_the_access_token_authenticates_the_user(): void
    {
        $user = User::factory()->promoter()->create();
        $tokens = $this->openSession($user);

        $this->withAccessToken($tokens->accessToken)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.type.value', 'promoter');
    }

    public function test_missing_forged_or_expired_tokens_are_rejected(): void
    {
        $tokens = $this->openSession(User::factory()->promoter()->create());

        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Session expirée : reconnectez-vous.');

        $forged = JWT::encode(['sub' => 'x', 'sid' => $tokens->session->id, 'jti' => 'x', 'typ' => 'user', 'iss' => config('jwt.issuer')], str_repeat('k', 48), 'HS256');
        $this->withAccessToken($forged)->getJson('/api/v1/me')->assertUnauthorized();

        $this->travel(16)->minutes();
        $this->withAccessToken($tokens->accessToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_refreshing_rotates_both_tokens(): void
    {
        $user = User::factory()->promoter()->create();
        $tokens = $this->openSession($user);

        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens->refreshToken])
            ->assertOk()
            ->assertJsonPath('session_id', $tokens->session->id)
            ->json();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens->refreshToken])->assertUnauthorized();
        $this->withAccessToken($tokens->accessToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withAccessToken($refreshed['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_session_cannot_be_refreshed_once_the_account_is_suspended(): void
    {
        $user = User::factory()->promoter()->create();
        $tokens = $this->openSession($user);

        $user->update(['status' => 'suspendu']);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens->refreshToken])->assertUnauthorized();
        $this->withAccessToken($tokens->accessToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_session_and_the_push_token_of_the_device(): void
    {
        $user = User::factory()->promoter()->create();
        $tokens = $this->openSession($user);

        $this->withAccessToken($tokens->accessToken)
            ->postJson('/api/v1/devices', ['push_token' => 'fcm:abc-123', 'platform' => 'ios', 'device_id' => 'device-iPhone 15'])
            ->assertNoContent();

        $this->withAccessToken($tokens->accessToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->withAccessToken($tokens->accessToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens->refreshToken])->assertUnauthorized();
        $this->assertNotNull(DeviceToken::sole()->revoked_at);
    }

    public function test_the_user_lists_and_disconnects_other_devices(): void
    {
        $user = User::factory()->promoter()->create();
        $phone = $this->openSession($user, 'iPhone 15');
        $tablet = $this->openSession($user, 'Galaxy Tab');
        $other = $this->openSession(User::factory()->promoter()->create(), 'Autre');

        $this->withAccessToken($phone->accessToken)->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['device_name' => 'iPhone 15', 'current' => true])
            ->assertJsonFragment(['device_name' => 'Galaxy Tab', 'current' => false]);

        $this->withAccessToken($phone->accessToken)->deleteJson("/api/v1/auth/sessions/{$other->session->id}")->assertNotFound();
        $this->withAccessToken($phone->accessToken)->deleteJson("/api/v1/auth/sessions/{$tablet->session->id}")->assertNoContent();

        $this->withAccessToken($tablet->accessToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withAccessToken($phone->accessToken)->getJson('/api/v1/auth/sessions')->assertJsonCount(1, 'data');
    }

    public function test_the_phone_number_is_changed_after_confirming_the_code_sent_to_the_new_number(): void
    {
        $user = User::factory()->promoter()->create(['phone' => '+2250708091011']);
        User::factory()->promoter()->create(['phone' => '+2250505050505']);
        $token = $this->openSession($user)->accessToken;

        $this->withAccessToken($token)->postJson('/api/v1/me/phone', ['phone' => '0505050505'])
            ->assertJsonValidationErrors(['phone' => 'Ce numéro est déjà utilisé par un autre compte.']);

        $this->withAccessToken($token)->postJson('/api/v1/me/phone', ['phone' => '0101010101'])->assertAccepted();
        $this->assertSame('+225 01 01 01 01 01', $this->sms->sent[0]['phone']);

        $this->withAccessToken($token)->postJson('/api/v1/me/phone/verify', ['phone' => '0101010101', 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+225 01 01 01 01 01');

        $this->assertSame('+2250101010101', $user->fresh()->phone);
    }
}
