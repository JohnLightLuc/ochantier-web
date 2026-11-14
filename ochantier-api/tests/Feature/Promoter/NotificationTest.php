<?php

namespace Tests\Feature\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Models\Notification;
use App\Models\PaymentSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class NotificationTest extends PromoterTestCase
{
    use ConfirmedSite;

    public function test_a_proposal_then_its_deposit_fill_the_notification_center(): void
    {
        $proposal = $this->acceptProposal();

        $this->getJson('/api/v1/promoter/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('meta.summary', '1 non lue')
            ->assertJsonPath('meta.tabs.*.key', ['all', 'unread', 'offres', 'paiements', 'chantiers'])
            ->assertJsonPath('meta.tabs.2.unread', 1)
            ->assertJsonPath('data.0.label', 'AUJOURD’HUI')
            ->assertJsonPath('data.0.items.0.event', 'proposal.received')
            ->assertJsonPath('data.0.items.0.title', 'Ouvriers trouvés · Résidence Les Palmiers')
            ->assertJsonPath('data.0.items.0.time', 'À l’instant')
            ->assertJsonPath('data.0.items.0.target.type', 'reservation')
            ->assertJsonPath('data.0.items.0.target.id', $proposal->offer_id)
            ->assertJsonPath('data.0.items.0.target.proposal_id', $proposal->id)
            ->assertJsonPath('data.0.items.0.actions', ['voir_offre', 'payer_acompte']);
        $this->assertStringContainsString('Ouvriers trouvés · Résidence Les Palmiers', collect($this->sms->sent)->last()['message']);

        $this->postJson('/api/v1/promoter/payments', ['proposal_id' => $proposal->id, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertCreated();

        $center = $this->getJson('/api/v1/promoter/notifications')
            ->assertJsonPath('meta.unread', 3)
            ->assertJsonPath('data.0.items.*.event', ['site.confirmed', 'payment.succeeded', 'proposal.received'])
            ->assertJsonPath('data.0.items.2.tag', ['label' => 'Acompte réglé', 'tone' => 'positive'])
            ->assertJsonPath('data.0.items.2.actions', [])
            ->json('data.0.items');

        $this->getJson('/api/v1/promoter/notifications?filter=paiements')->assertJsonPath('data.0.items.*.event', ['payment.succeeded']);
        $this->getJson('/api/v1/promoter/notifications?filter=inconnu')->assertJsonValidationErrors(['filter' => 'Filtre inconnu.']);

        $this->postJson("/api/v1/promoter/notifications/{$center[0]['id']}/read")->assertOk()->assertJsonPath('data.unread', 2);
        $this->getJson('/api/v1/promoter/notifications?filter=unread')->assertJsonCount(2, 'data.0.items');
        $this->postJson('/api/v1/promoter/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', 'Toutes les notifications sont lues')
            ->assertJsonPath('data.unread', 0);
        $this->getJson('/api/v1/promoter/notifications')->assertJsonPath('meta.summary', 'Tout est lu');

        $this->actingAsPromoter(User::factory()->promoter()->create());
        $this->getJson('/api/v1/promoter/notifications')->assertJsonPath('data', [])->assertJsonPath('meta.unread', 0);
    }

    public function test_notifications_reach_the_collaborators_whose_role_concerns_them(): void
    {
        $this->subscribeOwner();
        $organization = $this->promoter->ownedOrganizations()->create(['legal_name' => 'ADJEMIN']);
        $members = collect([OrganizationRole::ChefProjet, OrganizationRole::ResponsableFinancier, OrganizationRole::ResponsableChantier, OrganizationRole::Lecteur])
            ->mapWithKeys(function (OrganizationRole $role) use ($organization): array {
                $user = User::factory()->create(['user_type' => 'promoter']);
                $organization->members()->create(['user_id' => $user->id, 'role' => $role, 'status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);

                return [$role->value => $user];
            });

        $this->confirmSite();

        $events = fn (User $user) => Notification::query()->where('recipient_id', $user->id)->orderBy('created_at')->pluck('event_code')->all();
        $this->assertSame(['proposal.received', 'payment.succeeded', 'site.confirmed'], $events($this->promoter));
        $this->assertSame(['proposal.received', 'site.confirmed'], $events($members['chef_projet']));
        $this->assertSame(['payment.succeeded', 'site.confirmed'], $events($members['responsable_financier']));
        $this->assertSame(['site.confirmed'], $events($members['lecteur']));
        $this->assertSame([], $events($members['responsable_chantier']), 'Le technicien n’est notifié que pour ses chantiers.');
    }

    public function test_the_first_arrival_announces_the_start_of_the_site(): void
    {
        $this->confirmSite();
        $this->onWorkDay();

        $this->point($this->assignmentOf('Kouamé Koffi'))->assertCreated();
        $this->point($this->assignmentOf('Touré Ali'))->assertCreated();

        $this->assertSame(1, Notification::query()->where('event_code', 'site.started')->count());
        $this->getJson('/api/v1/promoter/notifications?filter=chantiers')
            ->assertJsonPath('data.0.items.0.title', 'Chantier démarré · Résidence Les Palmiers');
    }

    public function test_daily_reminders_announce_due_and_late_payments_and_days_to_validate(): void
    {
        $this->confirmSite();
        $due = CarbonImmutable::parse(PaymentSchedule::query()->where('amount_paid', 0)->min('due_date'));
        $amount = (int) PaymentSchedule::query()->whereDate('due_date', $due)->sum('amount_due');

        $this->travelTo($due->subDay()->setTime(8, 0));
        $this->artisan('ochantier:promoter-reminders')->expectsOutput('1 rappel(s) d’échéance, 0 retard(s) de paiement, 0 journée(s) à valider.')->assertSuccessful();
        $this->artisan('ochantier:promoter-reminders')->expectsOutput('0 rappel(s) d’échéance, 0 retard(s) de paiement, 0 journée(s) à valider.')->assertSuccessful();

        $reminder = Notification::query()->where('event_code', 'payment.due')->sole();
        $this->assertSame('Paiement à régler demain · Résidence Les Palmiers', $reminder->title);
        $this->assertStringStartsWith(number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA à régler le ", $reminder->body);

        $this->travelTo($due->addDay()->setTime(8, 0));
        $this->artisan('ochantier:promoter-reminders')->assertSuccessful();

        $late = Notification::query()->where('event_code', 'payment.late')->sole();
        $this->assertSame('Paiement en retard · Résidence Les Palmiers', $late->title);
        $this->assertStringContainsString('Paiement en retard', collect($this->sms->sent)->last()['message']);

        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertCreated();
        $this->travelTo($this->startDate->addDay()->setTime(8, 0));
        $this->artisan('ochantier:promoter-reminders')->assertSuccessful();

        $this->assertSame('Journée à valider · Résidence Les Palmiers', Notification::query()->where('event_code', 'attendance.pending')->sole()->title);
    }

    public function test_the_unread_count_feeds_the_bell_badge_and_follows_the_reads(): void
    {
        $this->getJson('/api/v1/promoter/notifications/unread-count')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => null, 'data' => ['unread_count' => 0]]);

        $this->seedNotifications(105, read: 2);

        $this->getJson('/api/v1/promoter/notifications/unread-count')->assertJsonPath('data.unread_count', 103);
        $center = $this->getJson('/api/v1/promoter/notifications')
            ->assertJsonPath('meta.unread', 103)
            ->assertJsonPath('meta.unread_count', 103)
            ->assertJsonPath('meta.summary', '103 non lues')
            ->json('data');
        $unread = collect($center)->pluck('items')->flatten(1)->firstWhere('unread', true);

        $this->postJson("/api/v1/promoter/notifications/{$unread['id']}/read")
            ->assertOk()
            ->assertJsonPath('data', ['unread' => 102, 'unread_count' => 102]);
        $this->getJson('/api/v1/promoter/notifications/unread-count')->assertJsonPath('data.unread_count', 102);

        $this->postJson('/api/v1/promoter/notifications/read-all')->assertJsonPath('data', ['unread' => 0, 'unread_count' => 0]);
        $this->getJson('/api/v1/promoter/notifications/unread-count')->assertJsonPath('data.unread_count', 0);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/promoter/notifications/unread-count')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Session expirée : reconnectez-vous.');
    }

    /**
     * Inscrit des notifications in_app pour l’utilisateur ; les « read » premières sont déjà lues.
     */
    private function seedNotifications(int $count, int $read = 0): void
    {
        $user = $this->promoter;

        Notification::query()->insert(collect(range(1, $count))->map(fn (int $index) => [
            'id' => (string) Str::uuid(),
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'event_code' => 'test.event',
            'channel' => 'in_app',
            'title' => 'Notification '.$index,
            'body' => 'Corps '.$index,
            'data' => '{}',
            'priority' => 'normale',
            'status' => 'livree',
            'read_at' => $index <= $read ? now() : null,
            'created_at' => now()->subMinutes($index),
        ])->all());
    }
}
