<?php

namespace Tests\Feature\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Invitation;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\CommercialOfferSeeder;
use Illuminate\Testing\TestResponse;

class TeamTest extends PromoterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CommercialOfferSeeder::class);
        $this->promoter->update(['first_name' => 'Nathanaël', 'last_name' => 'Yao']);
    }

    public function test_inviting_requires_a_subscription(): void
    {
        $this->getJson('/api/v1/promoter/team')
            ->assertOk()
            ->assertJsonPath('data.subscribed', false)
            ->assertJsonPath('data.can_invite', false)
            ->assertJsonPath('data.invite_refusal', 'Un abonnement est nécessaire pour inviter des collaborateurs.')
            ->assertJsonPath('data.seats.label', '1 / 1 utilisateur')
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.name', 'Nathanaël Yao (vous)')
            ->assertJsonPath('data.users.0.role.label', 'Propriétaire')
            ->assertJsonPath('data.roles.*.label', ['Administrateur', 'Chef de chantier', 'Technicien', 'Comptable', 'Observateur']);

        $this->invite()->assertJsonValidationErrors(['role' => 'Un abonnement est nécessaire pour inviter des collaborateurs.']);
    }

    public function test_a_collaborator_invited_by_sms_joins_the_account_at_first_login(): void
    {
        $this->subscribe();

        $this->invite()
            ->assertCreated()
            ->assertJsonPath('message', 'Invitation envoyée par SMS au +225 07 01 02 03 04')
            ->assertJsonPath('data.seats.label', '2 / 5 utilisateurs')
            ->assertJsonPath('data.users.1.name', 'Awa Traoré')
            ->assertJsonPath('data.users.1.role', ['value' => 'responsable_chantier', 'label' => 'Technicien'])
            ->assertJsonPath('data.users.1.status.value', 'invite')
            ->assertJsonPath('data.users.1.pending_text', 'Invitation envoyée par SMS');

        $this->assertSame('Nathanaël Yao', $this->promoter->ownedOrganizations()->sole()->legal_name);
        $this->assertStringContainsString('Nathanaël Yao vous invite à rejoindre « Nathanaël Yao » en tant que Technicien', end($this->sms->sent)['message']);
        $this->assertSame('+2250701020304', end($this->sms->sent)['phone']);

        $this->postJson('/api/v1/auth/promoter/login', ['dial_code' => '225', 'phone_number' => '0701020304'])->assertAccepted();
        $this->postJson('/api/v1/auth/promoter/login/verify', ['dial_code' => '225', 'phone_number' => '0701020304', 'code' => '123456', ...$this->device()])
            ->assertOk()
            ->assertJsonPath('data.user.first_name', 'Awa')
            ->assertJsonPath('data.user.promoter', null)
            ->assertJsonPath('data.user.promoter_account.owner.name', 'Nathanaël Yao')
            ->assertJsonPath('data.user.promoter_account.role.label', 'Technicien')
            ->assertJsonPath('data.user.promoter_account.is_owner', false)
            ->assertJsonPath('data.user.promoter_account.only_own_sites', true)
            ->assertJsonPath('data.user.promoter_account.permissions', ['sites.view', 'attendance.manage', 'steps.manage']);

        $member = OrganizationMember::query()->sole();
        $this->assertSame(OrganizationMemberStatus::Actif, $member->status);
        $this->assertNotNull($member->joined_at);
        $this->assertNotNull(Invitation::query()->sole()->accepted_at);
        $this->assertSame(['login'], AuditLog::query()->where('actor_id', $member->user_id)->pluck('action')->all());

        $this->getJson('/api/v1/promoter/team')
            ->assertJsonPath('data.users.1.status', ['value' => 'actif', 'label' => 'Actif'])
            ->assertJsonPath('data.users.1.pending_text', null);
    }

    public function test_an_invitation_by_email_and_its_expiry(): void
    {
        $this->subscribe();

        $this->invite(['channel' => 'email', 'email' => 'Awa.Traore@Exemple.ci', 'dial_code' => null, 'phone_number' => null])
            ->assertCreated()
            ->assertJsonPath('message', 'Invitation envoyée par e-mail à awa.traore@exemple.ci')
            ->assertJsonPath('data.users.1.contact', 'awa.traore@exemple.ci')
            ->assertJsonPath('data.users.1.pending_text', 'Invitation envoyée par e-mail');

        $this->travel(8)->days();
        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/team')
            ->assertJsonPath('data.users.1.status.value', 'invitation_expiree')
            ->assertJsonPath('data.users.1.pending_text', 'Invitation expirée · renvoyez-la');

        $this->invite(['channel' => 'email', 'email' => 'awa.traore@exemple.ci', 'dial_code' => null, 'phone_number' => null])
            ->assertCreated()
            ->assertJsonPath('data.users.1.status.value', 'invite');
        $this->assertSame(1, OrganizationMember::query()->count());
        $this->assertSame(1, Invitation::query()->whereNull('cancelled_at')->count());
    }

    public function test_invitations_are_refused_for_existing_accounts_members_and_full_plans(): void
    {
        $this->subscribe();
        User::factory()->promoter()->create(['dial_code' => '225', 'phone_number' => '0705050505']);

        $this->invite(['phone_number' => '0712345634'])->assertJsonValidationErrors(['phone_number' => 'Vous ne pouvez pas vous inviter vous-même.']);
        $this->invite(['phone_number' => '0705050505'])->assertJsonValidationErrors(['phone_number' => 'Ce numéro est déjà utilisé par un autre compte O’CHANTIER.']);
        $this->invite(['role' => 'proprietaire'])->assertJsonValidationErrors(['role' => 'Rôle inconnu.']);
        $this->invite(['channel' => 'email', 'email' => 'pas-un-email'])->assertJsonValidationErrors(['email' => 'Adresse e-mail invalide.']);

        $this->invite()->assertCreated();
        OrganizationMember::query()->update(['status' => OrganizationMemberStatus::Actif]);
        $this->invite()->assertJsonValidationErrors(['phone_number' => 'Awa Traoré fait déjà partie de votre équipe.']);

        foreach (['0702020202', '0703030303', '0704040404'] as $phone) {
            $this->invite(['phone_number' => $phone])->assertCreated();
        }

        $this->invite(['phone_number' => '0706060606'])->assertJsonValidationErrors(['role' => 'Limite de 5 utilisateurs atteinte · passez à la formule supérieure.']);
    }

    public function test_the_role_is_changed_and_a_removed_collaborator_loses_access(): void
    {
        $this->subscribe();
        $this->invite()->assertCreated();
        $member = OrganizationMember::query()->with('user')->sole();
        $member->update(['status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);
        $session = $this->openSession($member->user);

        $this->patchJson("/api/v1/promoter/team/members/{$member->id}", ['role' => 'responsable_financier'])
            ->assertOk()
            ->assertJsonPath('message', 'Awa Traoré est maintenant Comptable.')
            ->assertJsonPath('data.users.1.role.label', 'Comptable');
        $this->patchJson("/api/v1/promoter/team/members/{$member->id}", ['role' => 'chef'])->assertJsonValidationErrors(['role' => 'Rôle inconnu.']);

        $this->withAccessToken($session->accessToken)->getJson('/api/v1/promoter/payments')->assertOk();

        $this->actingAsPromoter($this->promoter);
        $this->deleteJson("/api/v1/promoter/team/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Awa Traoré retiré')
            ->assertJsonCount(1, 'data.users');

        $this->assertSame(OrganizationMemberStatus::Revoque, $member->refresh()->status);
        $this->assertSame(0, AuthSession::query()->whereMorphedTo('authenticatable', $member->user)->whereNull('revoked_at')->count());

        $this->actingAsPromoter($member->user);
        $this->getJson('/api/v1/promoter/sites')->assertForbidden()->assertJsonPath('message', 'Votre accès à ce compte a été retiré. Contactez son propriétaire.');

        $this->actingAsPromoter($this->promoter);
        $this->deleteJson("/api/v1/promoter/team/members/{$member->id}")->assertNotFound();
    }

    public function test_the_activity_log_is_reserved_to_subscribers(): void
    {
        $this->getJson('/api/v1/promoter/team/activity')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.locked', true);

        $this->subscribe();
        $this->postJson('/api/v1/promoter/sites', ['title' => 'Villa Riviera'])->assertCreated();
        $this->invite()->assertCreated();

        $this->getJson('/api/v1/promoter/team/activity')
            ->assertJsonPath('meta.locked', false)
            ->assertJsonPath('data.*.action', ['team.invitations.store', 'sites.store'])
            ->assertJsonPath('data.0.who', ['id' => $this->promoter->id, 'name' => 'Nathanaël Yao', 'role' => 'Propriétaire'])
            ->assertJsonPath('data.0.what', 'a invité un collaborateur')
            ->assertJsonPath('data.0.detail', 'Invitation envoyée par SMS au +225 07 01 02 03 04')
            ->assertJsonPath('data.1.what', 'a créé un chantier');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invite(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/promoter/team/invitations', [
            'first_name' => 'Awa',
            'last_name' => 'Traoré',
            'role' => OrganizationRole::ResponsableChantier->value,
            'channel' => 'sms',
            'dial_code' => '225',
            'phone_number' => '0701020304',
            ...$overrides,
        ]);
    }

    private function subscribe(): void
    {
        Subscription::query()->create([
            'reference' => 'ABO-TEST-0001',
            'promoter_profile_id' => $this->promoter->promoterProfile->id,
            'plan_id' => SubscriptionPlan::query()->where('code', 'equipe')->value('id'),
            'status' => SubscriptionStatus::Active,
            'started_at' => today(),
            'current_period_start' => today(),
            'current_period_end' => today()->addMonth()->subDay(),
        ]);
    }
}
