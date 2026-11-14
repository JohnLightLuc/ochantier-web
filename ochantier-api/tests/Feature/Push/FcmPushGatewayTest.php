<?php

namespace Tests\Feature\Push;

use App\Enums\DevicePlatform;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Push\FcmPushGateway;
use App\Support\Push\LogPushGateway;
use App\Support\Push\PushGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery\MockInterface;
use Tests\TestCase;

class FcmPushGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fcm_driver_is_used_when_configured(): void
    {
        $this->mock(Messaging::class);

        $this->assertInstanceOf(LogPushGateway::class, app(PushGateway::class));

        config(['services.push.driver' => 'fcm']);

        $this->assertInstanceOf(FcmPushGateway::class, app(PushGateway::class));
    }

    public function test_the_notification_is_sent_to_every_device_with_its_navigation_data(): void
    {
        $this->messaging()->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens): bool {
                $payload = $message->jsonSerialize();

                return $tokens === ['jeton-a', 'jeton-b']
                    && $payload['notification']['title'] === 'Proposition d’équipe reçue'
                    && $payload['notification']['body'] === '3 ouvriers vous sont proposés.'
                    && $payload['data'] === ['event' => 'proposal.received', 'target' => '{"type":"reservation","id":"0a1b"}']
                    && $payload['android']['priority'] === 'high'
                    && ! isset($payload['apns']['payload']['aps']['badge']);
            })
            ->andReturn($this->report(['jeton-a' => null, 'jeton-b' => null]));

        app(FcmPushGateway::class)->send(['jeton-a', 'jeton-b', 'jeton-a', ''], 'Proposition d’équipe reçue', '3 ouvriers vous sont proposés.', [
            'event' => 'proposal.received',
            'target' => ['type' => 'reservation', 'id' => '0a1b'],
            'extra' => null,
        ]);
    }

    public function test_the_unread_count_is_sent_in_the_data_and_as_the_ios_badge(): void
    {
        $this->messaging()->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message): bool {
                $payload = $message->jsonSerialize();

                return $payload['data'] === ['event' => 'worker.validated', 'unread_count' => '3']
                    && $payload['apns']['payload']['aps']['badge'] === 3
                    && $payload['apns']['payload']['aps']['sound'] === 'default';
            })
            ->andReturn($this->report(['jeton-a' => null]));

        app(FcmPushGateway::class)->send(['jeton-a'], 'Dossier validé', 'Jean Kouassi a été validé.', ['event' => 'worker.validated', 'unread_count' => 3]);
    }

    public function test_tokens_rejected_by_fcm_are_revoked(): void
    {
        $user = User::factory()->create();
        $valid = $this->device($user, 'jeton-valide');
        $unknown = $this->device($user, 'jeton-desinstalle');
        $invalid = $this->device($user, 'jeton-mal-forme');

        $this->messaging()->shouldReceive('sendMulticast')->once()->andReturn($this->report([
            'jeton-valide' => null,
            'jeton-desinstalle' => NotFound::becauseTokenNotFound('jeton-desinstalle'),
            'jeton-mal-forme' => new InvalidArgument('The registration token is not a valid FCM registration token'),
        ]));

        app(FcmPushGateway::class)->send(['jeton-valide', 'jeton-desinstalle', 'jeton-mal-forme'], 'Titre', 'Message');

        $this->assertNull($valid->refresh()->revoked_at);
        $this->assertNotNull($unknown->refresh()->revoked_at);
        $this->assertNotNull($invalid->refresh()->revoked_at);
    }

    public function test_an_fcm_outage_is_logged_without_interrupting_the_action(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'jeton-a');
        Log::spy();

        $this->messaging()->shouldReceive('sendMulticast')->once()->andThrow(new ServerUnavailable('FCM indisponible'));

        app(FcmPushGateway::class)->send(['jeton-a'], 'Titre', 'Message');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Push FCM non envoyé' && $context['error'] === 'FCM indisponible');
        $this->assertNull($device->refresh()->revoked_at);
    }

    public function test_a_temporary_failure_on_one_device_keeps_its_token(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user, 'jeton-a');
        Log::spy();

        $this->messaging()->shouldReceive('sendMulticast')->once()->andReturn($this->report(['jeton-a' => new ServerUnavailable('Réessayez plus tard')]));

        app(FcmPushGateway::class)->send(['jeton-a'], 'Titre', 'Message');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Push FCM partiellement échoué' && $context['failures'] === 1);
        $this->assertNull($device->refresh()->revoked_at);
    }

    public function test_large_audiences_are_sent_in_batches(): void
    {
        $tokens = array_map(fn (int $i) => 'jeton-'.$i, range(1, FcmPushGateway::BATCH_SIZE + 1));

        $this->messaging()->shouldReceive('sendMulticast')->twice()->andReturn($this->report([]));

        app(FcmPushGateway::class)->send($tokens, 'Titre', 'Message');
    }

    public function test_nothing_is_sent_without_a_token(): void
    {
        $this->messaging()->shouldNotReceive('sendMulticast');

        app(FcmPushGateway::class)->send([], 'Titre', 'Message');
    }

    public function test_navigation_data_is_converted_to_strings(): void
    {
        $this->assertSame(
            ['event' => 'site.suspended', 'target' => '{"type":"site","id":"abc"}', 'count' => '3', 'urgent' => 'true'],
            FcmPushGateway::data(['event' => 'site.suspended', 'target' => ['type' => 'site', 'id' => 'abc'], 'count' => 3, 'urgent' => true, 'empty' => null]),
        );
    }

    private function messaging(): MockInterface
    {
        return $this->mock(Messaging::class);
    }

    /**
     * Rapport d’envoi FCM : jeton => erreur (null si l’envoi a réussi).
     *
     * @param  array<string, \Throwable|null>  $results
     */
    private function report(array $results): MulticastSendReport
    {
        return MulticastSendReport::withItems(array_map(
            fn (string $token, ?\Throwable $error) => $error
                ? SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, $token), $error)
                : SendReport::success(MessageTarget::with(MessageTarget::TOKEN, $token), ['name' => 'projects/ochantier/messages/1']),
            array_keys($results),
            $results,
        ));
    }

    private function device(User $user, string $token): DeviceToken
    {
        return DeviceToken::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'platform' => DevicePlatform::Android,
            'push_token' => $token,
            'device_id' => 'appareil-'.$token,
            'last_seen_at' => now(),
        ]);
    }
}
