<?php

namespace Tests\Feature\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentSchedule;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;

class CollaboratorAccessTest extends PromoterTestCase
{
    use ConfirmedSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        Subscription::query()->create([
            'reference' => 'ABO-TEST-0001',
            'promoter_profile_id' => $this->promoter->promoterProfile->id,
            'plan_id' => SubscriptionPlan::query()->where('code', 'pro')->value('id'),
            'status' => SubscriptionStatus::Active,
            'started_at' => today(),
            'current_period_start' => today(),
            'current_period_end' => today()->addMonth()->subDay(),
        ]);
    }

    public function test_a_site_manager_works_on_the_owner_account(): void
    {
        $chef = $this->collaborator(OrganizationRole::ChefProjet, 'Moussa Koné');

        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.promoter_account.profile_id', $this->promoter->promoterProfile->id)
            ->assertJsonPath('data.promoter_account.role.label', 'Chef de chantier');
        $this->getJson('/api/v1/promoter/sites')->assertOk()->assertJsonPath('data.*.id', [$this->site->id]);
        $this->getJson('/api/v1/promoter/profile')->assertOk()->assertJsonPath('data.id', $this->promoter->promoterProfile->id);

        $created = $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->assertCreated()->json('data.id');
        $this->assertSame([$this->promoter->promoterProfile->id, $chef->id], [Site::query()->find($created)->promoter_profile_id, Site::query()->find($created)->created_by_user_id]);
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps", ['title' => 'Fondations'])->assertCreated();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/report")->assertOk();
        $this->getJson('/api/v1/promoter/payments')->assertOk();

        $this->getJson('/api/v1/promoter/payments/options')->assertForbidden()->assertJsonPath('message', 'Votre rôle ne permet pas cette action.');
        $this->getJson('/api/v1/promoter/team')->assertForbidden();
        $this->getJson('/api/v1/promoter/subscription')->assertForbidden();
        $this->patchJson('/api/v1/promoter/profile', ['first_name' => 'Pirate'])->assertForbidden();

        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/team/activity')
            ->assertJsonPath('data.0.who.name', 'Moussa Koné')
            ->assertJsonPath('data.0.who.role', 'Chef de chantier')
            ->assertJsonPath('data.0.what', 'a ajouté une étape')
            ->assertJsonPath('data.0.site', ['id' => $this->site->id, 'title' => 'Résidence Les Palmiers'])
            ->assertJsonPath('data.0.detail', 'Étape ajoutée : Fondations')
            ->assertJsonPath('data.1.what', 'a créé un chantier');
    }

    public function test_a_technician_only_reaches_the_sites_he_is_responsible_for(): void
    {
        $technician = $this->collaborator(OrganizationRole::ResponsableChantier, 'Awa Traoré');

        $this->getJson('/api/v1/promoter/sites')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}")->assertNotFound();
        $this->getJson('/api/v1/promoter/reservations')->assertOk()->assertJsonCount(0, 'data');

        $this->site->update(['responsible_user_id' => $technician->id]);

        $this->getJson('/api/v1/promoter/sites')->assertJsonPath('data.*.id', [$this->site->id]);
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/tracking")->assertOk();
        $this->getJson('/api/v1/promoter/reservations')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps", ['title' => 'Fondations'])->assertCreated();

        $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->assertForbidden();
        $this->getJson('/api/v1/promoter/payments')->assertForbidden();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/invoices")->assertForbidden();
        $this->postJson("/api/v1/promoter/assignments/{$this->assignmentOf('Kouamé Koffi')->id}/replacement", [])->assertForbidden();
    }

    public function test_an_accountant_pays_but_does_not_manage_the_sites_or_the_subscription(): void
    {
        $this->collaborator(OrganizationRole::ResponsableFinancier, 'Clarisse Ahoua');
        $schedule = PaymentSchedule::query()->where('amount_paid', 0)->orderBy('due_date')->firstOrFail();

        $this->getJson('/api/v1/promoter/payments/options')->assertOk();
        $this->postJson('/api/v1/promoter/payments/quote', ['schedule_ids' => [$schedule->id], 'operator_code' => 'wave'])->assertOk();
        $this->postJson('/api/v1/promoter/payments/quote', ['plan_code' => 'entreprise', 'operator_code' => 'wave'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Seul le propriétaire du compte gère l’abonnement.');

        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps", ['title' => 'Fondations'])->assertForbidden();
        $this->deleteJson("/api/v1/promoter/sites/{$this->site->id}")->assertForbidden();
        $this->getJson('/api/v1/promoter/team/activity')->assertForbidden();

        $this->postJson('/api/v1/promoter/payments', ['schedule_ids' => [$schedule->id], 'operator_code' => 'wave', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertCreated();
        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/team/activity')
            ->assertJsonPath('data.0.who.role', 'Comptable')
            ->assertJsonPath('data.0.what', 'a effectué un paiement')
            ->assertJsonPath('data.0.site.title', 'Résidence Les Palmiers');
    }

    public function test_an_observer_only_reads(): void
    {
        $this->collaborator(OrganizationRole::Lecteur, 'Serge Bamba');

        $this->getJson("/api/v1/promoter/sites/{$this->site->id}")->assertOk();
        $this->getJson("/api/v1/promoter/sites/{$this->site->id}/report")->assertOk();
        $this->getJson('/api/v1/promoter/payments')->assertOk();

        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/steps", ['title' => 'Fondations'])->assertForbidden();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/ratings", [])->assertForbidden();
        $this->getJson('/api/v1/promoter/payments/options')->assertForbidden();
    }

    public function test_an_administrator_manages_the_team_but_not_the_subscription(): void
    {
        $this->collaborator(OrganizationRole::Administrateur, 'Fatou Diallo');

        $this->getJson('/api/v1/promoter/team')->assertOk()->assertJsonPath('data.users.1.name', 'Fatou Diallo (vous)');
        $this->getJson('/api/v1/promoter/team/activity')->assertOk();
        $this->getJson('/api/v1/promoter/payments/options')->assertOk();
        $this->getJson('/api/v1/promoter/subscription')->assertForbidden();
        $this->post('/api/v1/promoter/profile/photo', ['photo' => $this->image()], ['Accept' => 'application/json'])->assertForbidden();

        $member = $this->promoter->ownedOrganizations()->sole()->members()->sole();
        $this->patchJson("/api/v1/promoter/team/members/{$member->id}", ['role' => 'lecteur'])->assertForbidden()->assertJsonPath('message', 'Vous ne pouvez pas changer votre propre rôle.');
    }

    public function test_a_promoter_without_account_is_refused(): void
    {
        $this->actingAsPromoter(User::factory()->create(['user_type' => 'promoter']));

        $this->getJson('/api/v1/promoter/sites')->assertForbidden()->assertJsonPath('message', 'Votre accès à ce compte a été retiré. Contactez son propriétaire.');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.promoter_account', null);
    }

    /**
     * Collaborateur actif du compte, connecté.
     */
    private function collaborator(OrganizationRole $role, string $name): User
    {
        [$first, $last] = explode(' ', $name, 2);
        $organization = $this->promoter->ownedOrganizations()->first() ?? $this->promoter->ownedOrganizations()->create(['legal_name' => 'ADJEMIN']);
        $user = User::factory()->create(['user_type' => 'promoter', 'first_name' => $first, 'last_name' => $last]);
        $organization->members()->create(['user_id' => $user->id, 'role' => $role, 'status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);
        $this->actingAsPromoter($user);

        return $user;
    }
}
