<?php

namespace Tests\Feature\Commercial;

use App\Models\Notification;
use App\Models\Prospect;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\HelpCenterSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Illuminate\Support\Str;

class CommercialProfileTest extends CommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->commercial->update(['first_name' => 'Adama', 'last_name' => 'Coulibaly', 'email' => 'adama@ochantier.ci']);
        $this->commercial->commercialProfile->commercialZones()->create(['commune_id' => $this->commune('Cocody'), 'is_primary' => true, 'effective_from' => today()->subMonth()]);
    }

    public function test_the_profile_shows_the_account_and_its_zone(): void
    {
        $this->getJson('/api/v1/commercial/profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Adama Coulibaly')
            ->assertJsonPath('data.role', 'Commercial terrain')
            ->assertJsonPath('data.status.label', 'Compte actif')
            ->assertJsonPath('data.phone', '+225 05 00 00 00 01')
            ->assertJsonPath('data.zones.*.commune.name', ['Cocody'])
            ->assertJsonPath('data.zone_request', null)
            ->assertJsonPath('data.activity.objective_label', 'Aucun objectif fixé ce mois')
            ->assertJsonPath('data.activity.ranking_label', '1ᵉʳ sur 1 commerciaux')
            ->assertJsonPath('data.notifications_label', 'Gérer mes préférences de notification');
    }

    public function test_the_commercial_updates_his_information(): void
    {
        $this->patchJson('/api/v1/commercial/profile', ['first_name' => '  Adama  ', 'last_name' => 'Coulibaly Bamba', 'email' => 'a.coulibaly@ochantier.ci'])
            ->assertOk()
            ->assertJsonPath('message', 'Informations mises à jour')
            ->assertJsonPath('data.name', 'Adama Coulibaly Bamba')
            ->assertJsonPath('data.email', 'a.coulibaly@ochantier.ci');

        $this->patchJson('/api/v1/commercial/profile', ['first_name' => ''])->assertJsonValidationErrors(['first_name' => 'Indiquez votre prénom.']);
        $this->patchJson('/api/v1/commercial/profile', ['email' => 'pas-un-email'])->assertJsonValidationErrors(['email' => 'Adresse e-mail invalide.']);
    }

    public function test_a_zone_change_is_requested_to_the_manager(): void
    {
        $this->postJson('/api/v1/commercial/zone-requests', ['commune_ids' => [$this->commune('Cocody')]])
            ->assertJsonValidationErrors(['commune_ids' => 'Modifiez au moins une commune.']);
        $this->postJson('/api/v1/commercial/zone-requests', ['commune_ids' => []])->assertJsonValidationErrors(['commune_ids' => 'Sélectionnez au moins une commune.']);

        $this->postJson('/api/v1/commercial/zone-requests', ['commune_ids' => [$this->commune('Cocody'), $this->commune('Yopougon')]])
            ->assertCreated()
            ->assertJsonPath('message', 'Demande envoyée à votre responsable')
            ->assertJsonPath('data.zone_request.communes.*.name', ['Cocody', 'Yopougon'])
            ->assertJsonPath('data.zone_request.label', 'Changement demandé : Cocody · Yopougon. En attente de validation.')
            ->assertJsonPath('data.zones.*.commune.name', ['Cocody']);

        $this->postJson('/api/v1/commercial/zone-requests', ['commune_ids' => [$this->commune('Abobo')]])
            ->assertJsonValidationErrors(['commune_ids' => 'Une demande de changement est déjà en attente de validation.']);
    }

    public function test_notification_preferences_use_the_commercial_alerts(): void
    {
        $this->getJson('/api/v1/commercial/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.alerts.*.key', ['dossiers', 'corrections', 'paiements', 'relances']);

        $this->putJson('/api/v1/commercial/notification-preferences', ['alerts' => ['relances' => false], 'channels' => ['sms' => false]])
            ->assertOk()
            ->assertJsonPath('data.alerts.3.enabled', false);
        $this->putJson('/api/v1/commercial/notification-preferences', ['alerts' => ['offres' => false]])->assertJsonValidationErrors(['alerts']);

        $this->getJson('/api/v1/commercial/profile')->assertJsonPath('data.notifications_label', '3 sur 5 activées');
    }

    public function test_the_help_center_lists_the_commercial_questions(): void
    {
        $this->seed(HelpCenterSeeder::class);

        $this->getJson('/api/v1/help')
            ->assertOk()
            ->assertJsonPath('data.faq.*.question', [
                'Comment changer ma zone d’activité ?',
                'Pourquoi un dossier est-il à corriger ?',
                'Quand ma commission est-elle versée ?',
            ])
            ->assertJsonPath('data.faq.2.section', 'Gains');
    }

    public function test_follow_ups_objective_and_ranking_reach_the_notification_center(): void
    {
        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class]);
        $id = $this->createProspect(['first_name' => 'Fatou', 'last_name' => 'Diallo']);
        $touched = CarbonImmutable::today()->subDays(8);
        Prospect::query()->whereKey($id)->update(['updated_at' => $touched]);
        $monday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY)->setTime(8, 0);
        $this->artisan('ochantier:simulate-commercial-objective', ['commercial' => $this->commercial->commercialProfile->employee_number, 'target' => 5, '--month' => $monday->format('Y-m')])->assertSuccessful();

        $this->travelTo($monday);
        $this->artisan('ochantier:commercial-reminders')->expectsOutput('1 relance(s) conseillée(s), 1 rappel(s) d’objectif, 1 classement(s).')->assertSuccessful();
        $this->artisan('ochantier:commercial-reminders')->expectsOutput('0 relance(s) conseillée(s), 0 rappel(s) d’objectif, 0 classement(s).')->assertSuccessful();

        $this->actingAsCommercial($this->commercial);
        $center = $this->getJson('/api/v1/commercial/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 3)
            ->assertJsonPath('meta.tabs.*.key', ['toutes', 'non_lues', 'dossiers', 'paiements'])
            ->assertJsonPath('meta.tabs.2.unread', 1)
            ->json('data.0.items');
        $this->assertEqualsCanonicalizing(['prospect.follow_up', 'objective.reminder', 'ranking.updated'], collect($center)->pluck('event')->all());

        $followUp = collect($center)->firstWhere('event', 'prospect.follow_up');
        $days = (int) $touched->diffInDays($monday->startOfDay());
        $this->assertSame(['Le dossier de Fatou Diallo est sans nouvelles depuis '.$days.' jours.', 'Voir le prospect', 'prospect', $id], [$followUp['body'], $followUp['cta'], $followUp['target']['type'], $followUp['target']['id']]);
        $this->assertSame('Vous êtes 1ᵉʳ sur 1 commerciaux ce mois-ci.', collect($center)->firstWhere('event', 'ranking.updated')['body']);

        $this->getJson('/api/v1/commercial/notifications?filter=dossiers')->assertJsonCount(1, 'data.0.items');
        $this->postJson("/api/v1/commercial/notifications/{$followUp['id']}/read")->assertOk()->assertJsonPath('data.unread', 2);
        $this->postJson('/api/v1/commercial/notifications/read-all')->assertOk()->assertJsonPath('message', 'Toutes les notifications sont lues');
        $this->assertSame(0, Notification::query()->whereNull('read_at')->count());
    }

    public function test_the_unread_count_feeds_the_bell_badge_and_follows_the_reads(): void
    {
        $this->getJson('/api/v1/commercial/notifications/unread-count')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['message' => null, 'data' => ['unread_count' => 0]]);

        $this->seedNotifications(105, read: 2);

        $this->getJson('/api/v1/commercial/notifications/unread-count')->assertJsonPath('data.unread_count', 103);
        $center = $this->getJson('/api/v1/commercial/notifications')
            ->assertJsonPath('meta.unread', 103)
            ->assertJsonPath('meta.unread_count', 103)
            ->assertJsonPath('meta.summary', '103 non lues')
            ->json('data');
        $unread = collect($center)->pluck('items')->flatten(1)->firstWhere('unread', true);

        $this->postJson("/api/v1/commercial/notifications/{$unread['id']}/read")
            ->assertOk()
            ->assertJsonPath('data', ['unread' => 102, 'unread_count' => 102]);
        $this->getJson('/api/v1/commercial/notifications/unread-count')->assertJsonPath('data.unread_count', 102);

        $this->postJson('/api/v1/commercial/notifications/read-all')->assertJsonPath('data', ['unread' => 0, 'unread_count' => 0]);
        $this->getJson('/api/v1/commercial/notifications/unread-count')->assertJsonPath('data.unread_count', 0);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/commercial/notifications/unread-count')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Session expirée : reconnectez-vous.');
    }

    /**
     * Inscrit des notifications in_app pour l’utilisateur ; les « read » premières sont déjà lues.
     */
    private function seedNotifications(int $count, int $read = 0): void
    {
        $user = $this->commercial;

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
