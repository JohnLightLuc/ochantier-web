<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorMemberRole;
use App\Enums\ContractorMemberStatus;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Contractor\ContractorNotifier;
use Database\Seeders\HelpCenterSeeder;
use Illuminate\Support\Str;

class ContractorAssistanceTest extends ContractorMarketTestCase
{
    public function test_the_help_center_lists_the_contractor_questions(): void
    {
        $this->seed(HelpCenterSeeder::class);

        $faq = $this->asContractor()->getJson('/api/v1/help')->assertOk()->json('data.faq');

        $this->assertContains('Pourquoi je ne vois pas certaines demandes ?', array_column($faq, 'question'));
        $this->assertNotContains('Comment réserver des ouvriers ?', array_column($faq, 'question'));
    }

    public function test_the_notification_center_groups_by_category_and_reaches_the_right_collaborators(): void
    {
        $chief = User::factory()->contractor()->create();
        $this->contractor->members()->create(['user_id' => $chief->id, 'role' => ContractorMemberRole::ChefChantier, 'status' => ContractorMemberStatus::Actif, 'joined_at' => now()]);
        $notifier = app(ContractorNotifier::class);

        $notifier->notify($this->contractor, 'opportunity.quote', 'Nouvelle demande de devis · Électricien', 'Villa Riviera');
        $notifier->notify($this->contractor, 'offer.attributed', 'Chantier attribué', 'Résidence Les Acacias');
        $notifier->notify($this->contractor, 'attendance.pending', 'Journée à valider', 'Résidence Les Acacias');

        $this->assertSame(3, Notification::query()->where('recipient_id', $this->user->id)->count());
        $this->assertSame(['attendance.pending'], Notification::query()->where('recipient_id', $chief->id)->pluck('event_code')->all());

        $this->asContractor()->getJson('/api/v1/contractor/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 3)
            ->assertJsonPath('data.0.items.0.category.value', 'chantiers');
        $first = $this->getJson('/api/v1/contractor/notifications?filter=nouvelles_demandes')->assertOk()->json('data.0.items');
        $this->assertCount(1, $first);
        $this->getJson('/api/v1/contractor/notifications?filter=inconnu')->assertUnprocessable();

        $this->postJson('/api/v1/contractor/notifications/'.$first[0]['id'].'/read')->assertOk()->assertJsonPath('data.unread', 2);
        $this->postJson('/api/v1/contractor/notifications/read-all')->assertOk()->assertJsonPath('message', 'Toutes les notifications sont lues');
        $this->getJson('/api/v1/contractor/notifications?filter=non_lues')->assertOk()->assertJsonPath('meta.unread', 0);

        $this->actingAsContractor($chief)->getJson('/api/v1/contractor/notifications')->assertOk()->assertJsonPath('meta.unread', 1);
    }

    public function test_notification_preferences_use_the_contractor_alerts(): void
    {
        $this->asContractor()->getJson('/api/v1/contractor/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.alerts.0.key', 'opportunites');

        $this->putJson('/api/v1/contractor/notification-preferences', ['alerts' => ['opportunites' => false]])->assertOk();
        $this->putJson('/api/v1/contractor/notification-preferences', ['alerts' => ['offres' => false]])->assertUnprocessable();
    }

    public function test_the_contractor_writes_to_support(): void
    {
        $this->asContractor()->postJson('/api/v1/contractor/support/tickets', ['subject' => 'Paiement', 'message' => 'Court'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'Décrivez votre demande (10 caractères minimum).');
        $this->postJson('/api/v1/contractor/support/tickets', ['subject' => 'Inconnu', 'message' => 'Une question précise'])->assertUnprocessable();

        $uuid = (string) Str::uuid();
        $ticket = $this->postJson('/api/v1/contractor/support/tickets', ['subject' => 'Paiement', 'message' => 'Je n’ai pas reçu la dernière échéance.', 'client_uuid' => $uuid])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'ouvert')
            ->assertJsonPath('data.category', 'paiement')
            ->json('data');
        $this->assertStringStartsWith('TK-', $ticket['reference']);
        $this->postJson('/api/v1/contractor/support/tickets', ['subject' => 'Paiement', 'message' => 'Je n’ai pas reçu la dernière échéance.', 'client_uuid' => $uuid])->assertCreated();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame('haute', Ticket::query()->sole()->priority->value);
        $this->getJson('/api/v1/contractor/support/tickets')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.subjects.0', 'Vérification du profil');
    }
}
