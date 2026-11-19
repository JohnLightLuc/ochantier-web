<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Models\Admin;
use App\Models\PricingSnapshot;
use App\Models\StaffingRequest;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;

/**
 * Registre des commissions O’CHANTIER PRO : frais de service des devis (collectés à la commande), commission de
 * l’acompte et des échéances d’un forfait (collectée au paiement, à collecter avant) et frais des recherches
 * d’effectifs.
 */
class ProCommissionLedgerTest extends ContractorMarketTestCase
{
    private string $financeToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->financeToken = $this->adminTokenFor(Admin::factory()->role('finance')->create());
    }

    public function test_the_register_lists_quote_fees_contract_commissions_and_staffing_fees(): void
    {
        $quoteSite = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $proposal = $this->asContractor()->postJson('/api/v1/contractor/opportunities/quotes/'.$this->line($quoteSite, 'electricien')->id.'/proposal', ['fee_amount' => 25000, 'visit_date' => now()->addDays(3)->toDateString(), 'visit_slot' => '10:00 – 12:00'])->json('data.id');
        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['quote_bid_ids' => [$proposal], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'devis-registre'])->assertCreated();

        $offer = $this->publishedContract('Résidence Les Acacias', 'Cocody');
        $bid = $this->asContractor()->postJson("/api/v1/contractor/opportunities/offers/{$offer->id}/response", [
            'mode' => 'contre_proposition', 'amount' => 3000000, 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(23)->toDateString(), 'team_size' => 6,
        ])->json('data.id');
        $this->asPromoter()->postJson("/api/v1/promoter/sites/{$offer->site_id}/contractor-offers/{$bid}/choose")->assertOk();
        $this->postJson('/api/v1/promoter/payments', ['contractor_bid_id' => $bid, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'acompte-registre'])->assertCreated();

        StaffingRequest::query()->create([
            'reference' => 'EF-2026-0001', 'site_id' => $offer->site_id, 'contractor_id' => $this->contractor->id, 'start_date' => now()->addDays(10), 'end_date' => now()->addDays(23), 'days' => 14,
            'status' => 'offre', 'deposit_percent' => 30, 'service_fee_percent' => 5, 'workers_amount' => 280000, 'deposit_amount' => 84000, 'service_fee_amount' => 14000, 'offered_at' => now(),
            'created_by_user_id' => $this->user->id,
        ]);

        $snapshot = PricingSnapshot::query()->where('offer_type', 'contractuelle')->sole();
        $all = $this->asFinance()->getJson('/api/v1/admin/pro-commissions?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.totals.by_source.demande_devis', ['collected' => 2500, 'to_collect' => 0])
            ->assertJsonPath('meta.totals.by_source.recherche_effectifs', ['collected' => 0, 'to_collect' => 14000])
            ->json();

        $contract = collect($all['data'])->where('source.value', 'offre_contractuelle');
        $this->assertSame($snapshot->promoter_commission_amount, $contract->sum('commission'), 'La commission du forfait est répartie sur l’acompte et les échéances.');
        $this->assertSame('Acompte', $contract->firstWhere('status.value', 'collectee')['label']);
        $this->assertSame($snapshot->promoter_commission_amount, $all['meta']['totals']['by_source']['offre_contractuelle']['collected'] + $all['meta']['totals']['by_source']['offre_contractuelle']['to_collect']);

        $this->asFinance()->getJson('/api/v1/admin/pro-commissions?source=demande_devis')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.label', 'Visite · Électricien')
            ->assertJsonPath('data.0.base', 27500)
            ->assertJsonPath('data.0.commission', 2500)
            ->assertJsonPath('data.0.professional_net', 25000)
            ->assertJsonPath('data.0.status', ['value' => 'collectee', 'label' => 'Collectée'])
            ->assertJsonPath('data.0.contractor.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.0.payer', 'Awa Kouamé');

        $this->asFinance()->getJson('/api/v1/admin/pro-commissions?status=a_collecter&contractor_id='.$this->contractor->id)
            ->assertJsonPath('meta.totals.commission.collectee', 0);
        $this->asFinance()->getJson('/api/v1/admin/pro-commissions?search=EF-2026')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.professional_net', null);
        $this->asFinance()->getJson('/api/v1/admin/pro-commissions?source=inconnue')->assertUnprocessable();
    }

    public function test_only_finance_readers_see_the_register(): void
    {
        $operations = $this->adminTokenFor(Admin::factory()->role('operations')->create());

        $this->withAccessToken($operations)->getJson('/api/v1/admin/pro-commissions')->assertForbidden();
        $this->asFinance()->getJson('/api/v1/admin/pro-commissions')->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }

    /**
     * @return $this
     */
    private function asFinance(): static
    {
        return $this->withAccessToken($this->financeToken);
    }
}
