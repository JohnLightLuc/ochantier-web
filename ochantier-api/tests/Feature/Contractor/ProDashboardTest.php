<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Models\Admin;
use App\Models\Contractor;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;

/**
 * O’CHANTIER PRO sur la vue d’ensemble (bloc, files à traiter, compteurs de la barre latérale) et paiements des
 * professionnels dans la liste des encaissements.
 */
class ProDashboardTest extends ContractorMarketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
    }

    public function test_the_overview_shows_the_pro_block_queues_and_badges(): void
    {
        Contractor::query()->create(['reference' => 'ENT-99999', 'kind' => 'entreprise', 'name' => 'BATI-CI', 'verification_status' => 'soumis']);
        $offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(23)->toDateString(), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$offer->site_id}/contractor-offers/{$bid}/choose")->assertOk();

        $direction = $this->adminTokenFor(Admin::factory()->role('super_admin')->create());

        $this->withAccessToken($direction)->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.pro.contractors', ['verified' => 1, 'to_review' => 1])
            ->assertJsonPath('data.pro.contract_offers', ['open' => 0, 'comparing' => 0, 'deposit' => 1, 'attributed' => 0])
            ->assertJsonPath('data.pro.quotes.open', 0)
            ->assertJsonPath('data.pro.commissions', ['collected_month' => 0, 'to_collect' => 0])
            ->assertJsonPath('data.pro.payouts', ['contractors' => 0, 'amount' => 0]);

        $priorities = collect($this->withAccessToken($direction)->getJson('/api/v1/admin/dashboard')->json('data.priorities'))->keyBy('key');
        $this->assertSame(1, $priorities['contractors']['count']);
        $this->assertSame(1, $priorities['contract_deposits']['count']);

        $this->withAccessToken($direction)->getJson('/api/v1/admin/dashboard/badges')
            ->assertJsonPath('data.badges.entrepreneurs', 1)
            ->assertJsonPath('data.badges.offres-contractuelles', 1);

        $support = $this->adminTokenFor(Admin::factory()->role('support')->create());
        $this->withAccessToken($support)->getJson('/api/v1/admin/dashboard')->assertJsonPath('data.pro', ['contractors' => ['verified' => 1, 'to_review' => 1]]);
    }

    public function test_contractor_payments_show_their_payer_in_the_payment_list(): void
    {
        $this->asContractor()->postJson('/api/v1/contractor/subscription', ['plan_code' => 'entrepreneur_essentiel', 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345678'], ['Idempotency-Key' => 'abonnement-pro'])->assertCreated();
        $finance = $this->adminTokenFor(Admin::factory()->role('finance')->create());

        $this->withAccessToken($finance)->getJson('/api/v1/admin/payments?payer=contractor')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.payer', ['type' => 'contractor', 'id' => $this->contractor->id, 'name' => 'Yao Serge Koffi', 'label' => 'Professionnel'])
            ->assertJsonPath('data.0.purpose.value', 'abonnement');

        $this->withAccessToken($finance)->getJson('/api/v1/admin/payments?search=Serge')->assertJsonPath('meta.total', 1);
        $this->withAccessToken($finance)->getJson('/api/v1/admin/payments?payer=promoter')->assertJsonPath('meta.total', 0);
        $this->withAccessToken($finance)->getJson('/api/v1/admin/payments?purpose=effectifs')->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }
}
