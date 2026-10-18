<?php

namespace Tests\Feature\Auth;

use App\Models\Country;
use App\Models\User;
use App\Support\Auth\AuthSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Auth\IssuedTokens;
use App\Support\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base des tests d’authentification : indicatif ivoirien ouvert, SMS capturés, code OTP fixe (phpunit.xml), session de test.
 */
abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @var object{sent: list<array{phone: string, message: string}>}
     */
    protected object $sms;

    protected function setUp(): void
    {
        parent::setUp();

        Country::query()->create(['iso_code' => 'CI', 'name' => 'Côte d’Ivoire', 'phone_prefix' => '+225', 'is_active' => true]);

        $this->sms = new class implements SmsGateway
        {
            /** @var list<array{phone: string, message: string}> */
            public array $sent = [];

            public function send(string $phone, string $message): void
            {
                $this->sent[] = ['phone' => $phone, 'message' => $message];
            }
        };

        $this->app->instance(SmsGateway::class, $this->sms);
    }

    /**
     * @return array<string, string>
     */
    protected function device(string $name = 'iPhone 15'): array
    {
        return ['device_name' => $name, 'device_id' => 'device-'.$name, 'platform' => 'ios', 'app_version' => '1.0.0'];
    }

    protected function openSession(User $user, string $device = 'iPhone 15'): IssuedTokens
    {
        return app(AuthSessions::class)->open($user, new DeviceContext($device, 'device-'.$device, 'ios', '1.0.0', '127.0.0.1', 'phpunit'));
    }

    /**
     * Requête suivante avec un autre jeton : la garde ne doit pas réutiliser l’utilisateur déjà résolu.
     *
     * @return $this
     */
    protected function withAccessToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
