<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\OneTimePassword;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Nombre de chiffres des codes OTP lu depuis OTP_LENGTH (services.otp.length).
 */
class OtpLengthTest extends AuthTestCase
{
    public function test_a_promoter_activates_the_account_with_a_four_digit_code(): void
    {
        config(['services.otp.length' => 4, 'services.otp.test_code' => '1234']);
        $phone = ['dial_code' => '225', 'phone_number' => '0708091011'];

        $this->postJson('/api/v1/auth/promoter/register', [...$phone, 'profile_kind' => 'particulier', 'accept_terms' => true])
            ->assertAccepted()
            ->assertJsonPath('data.code_length', 4);

        $this->assertStringContainsString(' est 1234.', $this->sms->sent[0]['message']);

        $this->postJson('/api/v1/auth/promoter/register/verify', [...$phone, 'code' => '1234', ...$this->device()])
            ->assertCreated();
    }

    public function test_a_generated_code_has_the_configured_length_and_signs_the_promoter_in(): void
    {
        config(['services.otp.length' => 8, 'services.otp.test_code' => null]);
        User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0708091011']);
        $phone = ['dial_code' => '225', 'phone_number' => '0708091011'];

        $this->postJson('/api/v1/auth/promoter/login', $phone)
            ->assertAccepted()
            ->assertJsonPath('data.code_length', 8);

        $this->assertMatchesRegularExpression('/ est (\d{8})\. /', $this->sms->sent[0]['message']);
        preg_match('/ est (\d{8})\. /', $this->sms->sent[0]['message'], $matches);

        $this->postJson('/api/v1/auth/promoter/login/verify', [...$phone, 'code' => substr($matches[1], 0, 6), ...$this->device()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Saisissez les 8 chiffres du code.']);

        $this->postJson('/api/v1/auth/promoter/login/verify', [...$phone, 'code' => $matches[1], ...$this->device()])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
    }

    public function test_a_commercial_code_must_have_the_configured_length(): void
    {
        config(['services.otp.length' => 8, 'services.otp.test_code' => '12345678']);
        User::factory()->commercial()->create(['dial_code' => '225', 'phone_number' => '0102030405']);
        $phone = ['dial_code' => '225', 'phone_number' => '0102030405'];

        $this->postJson('/api/v1/auth/commercial/login', [...$phone, 'password' => 'motdepasse1'])
            ->assertAccepted()
            ->assertJsonPath('data.code_length', 8);

        $this->postJson('/api/v1/auth/commercial/login/verify', [...$phone, 'code' => '123456', ...$this->device()])
            ->assertJsonValidationErrors(['code' => 'Saisissez les 8 chiffres du code.']);

        $this->postJson('/api/v1/auth/commercial/login/verify', [...$phone, 'code' => '12345678', ...$this->device()])
            ->assertOk();
    }

    public function test_the_phone_change_code_must_have_the_configured_length(): void
    {
        config(['services.otp.length' => 4, 'services.otp.test_code' => '1234']);
        $user = User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0708091011']);
        $token = $this->openSession($user)->accessToken;
        $phone = ['dial_code' => '225', 'phone_number' => '0101010101'];

        $this->withAccessToken($token)->postJson('/api/v1/me/phone', $phone)
            ->assertAccepted()
            ->assertJsonPath('data.code_length', 4);

        $this->withAccessToken($token)->postJson('/api/v1/me/phone/verify', [...$phone, 'code' => '123456'])
            ->assertJsonValidationErrors(['code' => 'Saisissez les 4 chiffres du code.']);

        $this->withAccessToken($token)->postJson('/api/v1/me/phone/verify', [...$phone, 'code' => '1234'])
            ->assertOk();

        $this->assertSame('+2250101010101', $user->fresh()->phone);
    }

    #[DataProvider('configuredLengths')]
    public function test_the_length_is_kept_between_four_and_ten_digits(mixed $configured, int $expected): void
    {
        config(['services.otp.length' => $configured]);

        $this->assertSame($expected, OneTimePassword::length());
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function configuredLengths(): array
    {
        return [
            'valeur du .env' => ['7', 7],
            'trop court' => [2, 4],
            'trop long' => [20, 10],
            'vide' => ['', 6],
        ];
    }
}
