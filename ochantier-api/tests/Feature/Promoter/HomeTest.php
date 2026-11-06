<?php

namespace Tests\Feature\Promoter;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Models\User;

class HomeTest extends PromoterTestCase
{
    use ConfirmedSite;

    public function test_a_new_promoter_sees_an_empty_home(): void
    {
        $this->getJson('/api/v1/promoter/home')
            ->assertOk()
            ->assertJsonPath('data.greeting', ['title' => 'BONJOUR', 'name' => 'Bienvenue', 'subtitle' => 'Trouvez des ouvriers pour vos chantiers ici'])
            ->assertJsonPath('data.unread_notifications', 0)
            ->assertJsonPath('data.sites', [])
            ->assertJsonPath('data.sites_count', 0)
            ->assertJsonPath('data.top_trades.*.code', ['macon', 'electricien', 'plombier'])
            ->assertJsonPath('data.pending_payments.count', 0);
    }

    public function test_the_home_gathers_sites_trades_payments_and_unread_notifications(): void
    {
        $this->promoter->update(['first_name' => 'Adama']);
        $this->confirmSite();
        $this->postJson('/api/v1/promoter/sites', ['title' => 'Brouillon'])->assertCreated();

        $home = $this->getJson('/api/v1/promoter/home')
            ->assertOk()
            ->assertJsonPath('data.greeting.name', 'Adama')
            ->assertJsonPath('data.unread_notifications', 3)
            ->assertJsonPath('data.sites.*.title', ['Résidence Les Palmiers'])
            ->assertJsonPath('data.sites_count', 1)
            ->assertJsonPath('data.pending_payments.count', 2)
            ->assertJsonPath('data.pending_payments.items.0.short', 'Échéance 1/2')
            ->json('data');

        $this->assertEqualsCanonicalizing(['macon', 'peintre', 'electricien'], collect($home['top_trades'])->pluck('code')->all(), 'Métiers demandés, complétés par les métiers usuels.');
        $this->assertSame($home['pending_payments']['total'], collect($home['pending_payments']['items'])->sum('amount'));
    }

    public function test_a_technician_does_not_see_payments_on_the_home(): void
    {
        $this->confirmSite();
        $this->subscribeOwner();
        $user = User::factory()->create(['user_type' => 'promoter', 'first_name' => 'Awa']);
        $this->promoter->ownedOrganizations()->create(['legal_name' => 'ADJEMIN'])->members()->create(['user_id' => $user->id, 'role' => OrganizationRole::ResponsableChantier, 'status' => OrganizationMemberStatus::Actif, 'joined_at' => now()]);
        $this->actingAsPromoter($user);

        $this->getJson('/api/v1/promoter/home')
            ->assertOk()
            ->assertJsonPath('data.greeting.name', 'Awa')
            ->assertJsonPath('data.sites_count', 0)
            ->assertJsonPath('data.pending_payments', null);
    }
}
