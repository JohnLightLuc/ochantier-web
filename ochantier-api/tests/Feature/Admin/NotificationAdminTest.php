<?php

namespace Tests\Feature\Admin;

use App\Enums\DevicePlatform;
use App\Enums\UserStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Push\PushGateway;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Support\Str;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office : notification envoyée à un utilisateur des applications mobiles.
 */
class NotificationAdminTest extends CommercialTestCase
{
    /**
     * Push reçus par le faux pilote : [jetons, titre, corps, données].
     *
     * @var list<array{0: list<string>, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AdminAccessSeeder::class);
        $this->app->instance(PushGateway::class, new class($this->pushes) implements PushGateway
        {
            /**
             * @param  list<array{0: list<string>, 1: string, 2: string, 3: array<string, mixed>}>  $pushes
             */
            public function __construct(private array &$pushes) {}

            public function send(array $tokens, string $title, string $body, array $data = []): void
            {
                $this->pushes[] = [$tokens, $title, $body, $data];
            }
        });
        DeviceToken::query()->create([
            'owner_type' => $this->commercial->getMorphClass(),
            'owner_id' => $this->commercial->getKey(),
            'platform' => DevicePlatform::Android,
            'push_token' => 'jeton-commercial',
            'device_id' => 'appareil-commercial',
            'last_seen_at' => now(),
        ]);
    }

    public function test_the_back_office_sends_a_push_that_lands_in_the_notification_center(): void
    {
        $admin = Admin::factory()->role('support')->create();

        $sent = $this->asAdmin($admin)->postJson('/api/v1/admin/notifications', [
            'recipient_type' => 'commercial',
            'recipient_id' => $this->commercial->commercialProfile->id,
            'title' => '  Réunion d’équipe  ',
            'body' => 'Rendez-vous lundi à 9 h au bureau de Cocody.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Notification envoyée.')
            ->assertJsonPath('data.event', 'admin.message')
            ->assertJsonPath('data.title', 'Réunion d’équipe')
            ->assertJsonPath('data.recipient.id', $this->commercial->commercialProfile->id)
            ->assertJsonPath('data.recipient.type', ['value' => 'commercial', 'label' => 'Commercial'])
            ->assertJsonPath('data.push', ['sent' => true, 'devices' => 1])
            ->assertJsonPath('data.sms', ['sent' => false])
            ->assertJsonPath('data.read_at', null)
            ->json('data');

        $this->assertCount(1, $this->pushes);
        [$tokens, $title, $body, $data] = $this->pushes[0];
        $this->assertSame(['jeton-commercial'], $tokens);
        $this->assertSame(['Réunion d’équipe', 'Rendez-vous lundi à 9 h au bureau de Cocody.'], [$title, $body]);
        $this->assertSame(['notification_id' => $sent['id'], 'event' => 'admin.message', 'target' => null, 'unread_count' => 1], $data);
        $this->assertSame([], $this->sms->sent);

        $audit = AuditLog::query()->where('action', 'notification.sent')->sole();
        $this->assertSame([$admin->id, $this->commercial->id], [$audit->actor_id, $audit->after_values['recipient_user_id']]);

        $this->app['auth']->forgetGuards();
        $this->actingAsCommercial($this->commercial)->getJson('/api/v1/commercial/notifications')
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.items.0.event', 'admin.message')
            ->assertJsonPath('data.0.items.0.title', 'Réunion d’équipe')
            ->assertJsonPath('data.0.items.0.unread', true);
    }

    public function test_the_sms_is_sent_only_on_request(): void
    {
        $this->asAdmin()->postJson('/api/v1/admin/notifications', [
            'recipient_type' => 'commercial',
            'recipient_id' => $this->commercial->commercialProfile->id,
            'title' => 'Paiement des commissions',
            'body' => 'Vos commissions d’octobre sont versées.',
            'send_sms' => true,
        ])->assertCreated()->assertJsonPath('data.sms', ['sent' => true]);

        $this->assertStringContainsString('Paiement des commissions', collect($this->sms->sent)->last()['message']);
    }

    public function test_a_replayed_client_uuid_does_not_send_twice(): void
    {
        $payload = ['recipient_type' => 'commercial', 'recipient_id' => $this->commercial->commercialProfile->id, 'title' => 'Rappel', 'body' => 'Pensez à relancer vos prospects.', 'client_uuid' => (string) Str::uuid()];

        $first = $this->asAdmin()->postJson('/api/v1/admin/notifications', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/notifications', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Notification déjà envoyée.')
            ->assertJsonPath('data.id', $first);

        $this->assertSame(1, Notification::query()->where('event_code', 'admin.message')->count());
        $this->assertCount(1, $this->pushes);
    }

    public function test_only_active_mobile_users_can_be_notified(): void
    {
        $this->asAdmin()->postJson('/api/v1/admin/notifications', ['recipient_type' => 'commercial', 'recipient_id' => (string) Str::uuid(), 'title' => 'Titre', 'body' => 'Message'])
            ->assertJsonValidationErrors(['recipient_id' => 'Destinataire introuvable.']);
        $this->postJson('/api/v1/admin/notifications', ['recipient_type' => 'promoter', 'recipient_id' => $this->commercial->commercialProfile->id, 'title' => 'Titre', 'body' => 'Message'])
            ->assertJsonValidationErrors(['recipient_id' => 'Destinataire introuvable.']);
        $this->postJson('/api/v1/admin/notifications', ['recipient_type' => 'worker', 'recipient_id' => (string) Str::uuid(), 'title' => 'Titre', 'body' => 'Message'])
            ->assertJsonValidationErrors(['recipient_type' => 'Type de destinataire attendu : promoter ou commercial.']);
        $this->postJson('/api/v1/admin/notifications', ['recipient_type' => 'commercial', 'recipient_id' => $this->commercial->commercialProfile->id, 'title' => '', 'body' => str_repeat('a', 501)])
            ->assertJsonValidationErrors(['title' => 'Indiquez le titre de la notification.', 'body' => 'Le message ne doit pas dépasser 500 caractères.']);

        $suspended = User::factory()->commercial()->create(['phone_number' => '0500000077', 'status' => UserStatus::Suspendu]);
        $this->postJson('/api/v1/admin/notifications', ['recipient_type' => 'commercial', 'recipient_id' => $suspended->commercialProfile->id, 'title' => 'Titre', 'body' => 'Message'])
            ->assertJsonValidationErrors(['recipient_id' => 'Ce compte n’est pas actif : la notification ne peut pas lui être envoyée.']);

        $this->assertSame(0, Notification::query()->count());
        $this->assertSame([], $this->pushes);
    }

    public function test_sending_requires_the_notifications_create_permission(): void
    {
        $this->asAdmin(Admin::factory()->role('finance')->create())
            ->postJson('/api/v1/admin/notifications', ['recipient_type' => 'commercial', 'recipient_id' => $this->commercial->commercialProfile->id, 'title' => 'Titre', 'body' => 'Message'])
            ->assertForbidden();

        $this->assertSame(0, Notification::query()->count());
    }

    private function asAdmin(?Admin $admin = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withAccessToken(app(AdminSessions::class)->open($admin ?? Admin::factory()->role('support')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }
}
