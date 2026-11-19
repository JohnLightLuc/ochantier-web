<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorMemberStatus;
use App\Enums\ContractorVerificationStatus;
use App\Models\ContractorMember;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Testing\TestResponse;

class ContractorMemberTest extends ContractorMarketTestCase
{
    private Offer $offer;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $this->site = $this->offer->site_id;

        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$this->offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(39)->toDateString(), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$this->site}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-membres'])->assertCreated();
        $this->asContractor();
    }

    public function test_collaborators_require_a_subscription_and_fit_the_plan(): void
    {
        $this->getJson('/api/v1/contractor/members')
            ->assertOk()
            ->assertJsonPath('data.plan', 'Solo')
            ->assertJsonPath('data.usage_label', '1 / 1 utilisateur')
            ->assertJsonPath('data.can_invite', false)
            ->assertJsonPath('data.members.0.role.label', 'Titulaire');
        $this->invite('Awa', '0701010101', 'chef_chantier')->assertForbidden();

        $this->subscribe();

        $this->invite('Awa', '0701010101', 'chef_chantier')
            ->assertCreated()
            ->assertJsonPath('message', 'Invitation envoyée à Awa par SMS.')
            ->assertJsonPath('data.usage_label', '2 / 5 utilisateurs')
            ->assertJsonPath('data.members.1.status.value', 'invite')
            ->assertJsonPath('data.members.1.role.label', 'Chef de chantier');
        $this->assertStringContainsString('Mot de passe oublié', collect($this->sms->sent)->last()['message']);

        $this->invite('Awa', '0701010101', 'technicien')
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone_number.0', 'Awa fait déjà partie de votre équipe.');
        $this->invite('Moi', '0712345678', 'technicien')
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone_number.0', 'Vous ne pouvez pas vous inviter vous-même.');

        foreach (['0702020202', '0703030303', '0704040404'] as $phone) {
            $this->invite('Collaborateur', $phone, 'observateur')->assertCreated();
        }

        $this->invite('Sixième', '0705050505', 'observateur')
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'Limite de 5 utilisateurs atteinte pour votre formule.');
    }

    public function test_the_invitee_joins_at_first_login_with_the_rights_of_the_role(): void
    {
        $this->subscribe();
        $this->invite('Awa Konan', '0701010101', 'technicien')->assertCreated();

        $this->postJson('/api/v1/auth/contractor/password/forgot', ['dial_code' => '225', 'phone_number' => '0701010101'])->assertAccepted();
        $this->postJson('/api/v1/auth/contractor/password/reset', ['dial_code' => '225', 'phone_number' => '0701010101', 'code' => '123456', 'password' => 'chantier2026', 'password_confirmation' => 'chantier2026'])->assertOk();
        $token = $this->postJson('/api/v1/auth/contractor/login', ['dial_code' => '225', 'phone_number' => '0701010101', 'password' => 'chantier2026', ...$this->device()])
            ->assertOk()
            ->json('data.access_token');

        $this->assertSame(ContractorMemberStatus::Actif, ContractorMember::query()->sole()->status);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'team.joined')->exists());

        $this->withAccessToken($token)->getJson('/api/v1/me')
            ->assertJsonPath('data.contractor.profile_chosen', true)
            ->assertJsonPath('data.contractor.role', ['value' => 'technicien', 'label' => 'Technicien'])
            ->assertJsonPath('data.contractor.abilities', ['view', 'attendance.record'])
            ->assertJsonPath('data.contractor.profile.name', 'Yao Serge Koffi');

        $this->getJson('/api/v1/contractor/sites')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/contractor/sites/{$this->site}/attendance")->assertOk();
        $this->getJson("/api/v1/contractor/sites/{$this->site}/payments")
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre rôle ne permet pas cette action.');
        $this->postJson("/api/v1/contractor/sites/{$this->site}/steps", ['title' => 'Gros œuvre'])->assertForbidden();
        $this->patchJson('/api/v1/contractor/profile', ['description' => 'Test'])->assertForbidden();
        $this->getJson('/api/v1/contractor/members')->assertForbidden();
        $this->postJson('/api/v1/contractor/profile', ['kind' => 'independant'])->assertForbidden();

        $member = ContractorMember::query()->sole();
        $this->asContractor()->patchJson("/api/v1/contractor/members/{$member->id}", ['role' => 'chef_chantier'])
            ->assertOk()
            ->assertJsonPath('data.members.1.role.value', 'chef_chantier');

        $this->withAccessToken($token)->postJson("/api/v1/contractor/sites/{$this->site}/steps", ['title' => 'Gros œuvre'])->assertCreated();
        $this->getJson("/api/v1/contractor/sites/{$this->site}/payments")->assertOk();
        $this->getJson('/api/v1/contractor/subscription')->assertForbidden();

        $this->asContractor()->deleteJson("/api/v1/contractor/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Awa Konan a été retiré de l’équipe.')
            ->assertJsonPath('data.used', 1);
        $this->withAccessToken($token)->getJson('/api/v1/contractor/sites')->assertUnauthorized();
    }

    public function test_an_account_owner_cannot_be_invited(): void
    {
        $this->subscribe();
        $other = User::factory()->contractor()->create(['dial_code' => '225', 'phone_number' => '0709090909']);
        $other->contractor()->create(['reference' => 'ENT-55555', 'kind' => 'entreprise', 'name' => 'Autre entreprise']);

        $this->invite('Autre', '0709090909', 'technicien')
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone_number.0', 'Ce numéro est déjà utilisé par un autre compte O’CHANTIER Pro.');
    }

    private function invite(string $name, string $phone, string $role): TestResponse
    {
        return $this->postJson('/api/v1/contractor/members', ['first_name' => $name, 'role' => $role, 'channel' => 'sms', 'dial_code' => '225', 'phone_number' => $phone]);
    }
}
