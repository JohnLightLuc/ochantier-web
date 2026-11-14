<?php

namespace Tests\Feature\Push;

use App\Enums\DevicePlatform;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\Commercial\CommercialNotifier;
use App\Support\Notifications\NotificationInbox;
use App\Support\Push\PushGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationInboxPushTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Envois reçus par le faux pilote push : [jetons, titre, données].
     *
     * @var list<array{0: list<string>, 1: string, 2: array<string, mixed>}>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PushGateway::class, new class($this->sent) implements PushGateway
        {
            /**
             * @param  list<array{0: list<string>, 1: string, 2: array<string, mixed>}>  $sent
             */
            public function __construct(private array &$sent) {}

            public function send(array $tokens, string $title, string $body, array $data = []): void
            {
                $this->sent[] = [$tokens, $title, $data];
            }
        });
    }

    public function test_each_push_carries_the_unread_count_including_the_new_notification(): void
    {
        $user = User::factory()->commercial()->create();
        $this->device($user, 'jeton-a');

        $this->notify($user, 'Premier dossier validé');
        $this->notify($user, 'Second dossier validé');

        $this->assertSame([1, 2], array_map(fn (array $push) => $push[2]['unread_count'], $this->sent));
        $this->assertSame(['jeton-a'], $this->sent[1][0]);
        $this->assertSame('worker.validated', $this->sent[1][2]['event']);

        app(NotificationInbox::class)->markRead($user);
        $this->notify($user, 'Troisième dossier validé');

        $this->assertSame(1, $this->sent[2][2]['unread_count']);
    }

    public function test_the_count_only_covers_the_notifications_of_the_recipient(): void
    {
        $user = User::factory()->commercial()->create();
        $other = User::factory()->commercial()->create(['phone_number' => '0500000099']);
        $this->device($user, 'jeton-a');

        $this->notify($other, 'Dossier d’un autre commercial');
        $this->notify($user, 'Dossier validé');

        $this->assertCount(1, $this->sent);
        $this->assertSame(1, $this->sent[0][2]['unread_count']);
    }

    private function notify(User $user, string $title): void
    {
        app(NotificationInbox::class)->send($user, 'worker.validated', CommercialNotifier::EVENTS['worker.validated'], $title, 'Commission confirmée.');
    }

    private function device(User $user, string $token): void
    {
        DeviceToken::query()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'platform' => DevicePlatform::Android,
            'push_token' => $token,
            'device_id' => 'appareil-'.$token,
            'last_seen_at' => now(),
        ]);
    }
}
