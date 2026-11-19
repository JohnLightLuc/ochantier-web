<?php

namespace Tests\Feature\Contractor;

use App\Enums\ContractorVerificationStatus;
use App\Models\Admin;
use App\Models\ContractorPayout;
use App\Models\Notification;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;

/**
 * Reversements aux professionnels : parts nettes dues après le service (visite faite, échéance payée), numéro
 * Mobile Money confirmé par une autre personne, versement préparé par l’un et validé (envoyé) par l’autre.
 */
class ContractorPayoutTest extends ContractorMarketTestCase
{
    private string $preparer;

    private string $approver;

    private string $proposal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractor->update(['verification_status' => ContractorVerificationStatus::Valide]);
        $this->preparer = $this->adminTokenFor(Admin::factory()->role('finance')->create());
        $this->approver = $this->adminTokenFor(Admin::factory()->role('finance')->create());

        $site = $this->quoteRequest('Villa Riviera', 'Cocody', ['electricien']);
        $this->proposal = $this->asContractor()->postJson('/api/v1/contractor/opportunities/quotes/'.$this->line($site, 'electricien')->id.'/proposal', ['fee_amount' => 25000, 'visit_date' => now()->addDays(3)->toDateString(), 'visit_slot' => '10:00 – 12:00'])->json('data.id');
        $this->asPromoter()->postJson('/api/v1/promoter/payments', ['quote_bid_ids' => [$this->proposal], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712349999'], ['Idempotency-Key' => 'devis-versement'])->assertCreated();
    }

    public function test_the_net_share_is_paid_after_the_visit_with_two_people(): void
    {
        $this->as($this->preparer)->getJson('/api/v1/admin/contractor-payouts')->assertOk()->assertJsonPath('data.payable', []);

        $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$this->proposal}/visit/done")->assertOk();

        $this->as($this->preparer)->getJson('/api/v1/admin/contractor-payouts')
            ->assertJsonPath('data.payable.0.contractor.name', 'Yao Serge Koffi')
            ->assertJsonPath('data.payable.0.total', 25000)
            ->assertJsonPath('data.payable.0.blockers', ['Aucun numéro Mobile Money confirmé'])
            ->assertJsonPath('data.payable.0.items.0.label', 'Visite · Électricien');

        $this->as($this->preparer)->postJson('/api/v1/admin/contractor-payouts', ['contractor_id' => $this->contractor->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'Aucun numéro Mobile Money confirmé.');

        $method = $this->as($this->preparer)->postJson('/api/v1/admin/contractors/'.$this->contractor->id.'/payment-method', ['operator_id' => PaymentOperator::query()->where('code', 'orange_money')->value('id'), 'dial_code' => '225', 'phone_number' => '0712345678'])
            ->assertCreated()
            ->assertJsonPath('data.pending.holder', 'Yao Serge Koffi')
            ->json('data.pending.id');
        $this->as($this->preparer)->postJson('/api/v1/admin/contractors/'.$this->contractor->id.'/payment-method/'.$method.'/approve')
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'Une autre personne doit confirmer ce numéro.');
        $this->as($this->approver)->postJson('/api/v1/admin/contractors/'.$this->contractor->id.'/payment-method/'.$method.'/approve')->assertOk();

        $payout = $this->as($this->preparer)->postJson('/api/v1/admin/contractor-payouts', ['contractor_id' => $this->contractor->id])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 25000)
            ->assertJsonPath('data.status.value', 'a_payer')
            ->assertJsonPath('data.items.0.amount', 25000)
            ->assertJsonPath('data.actions.approve', false)
            ->json('data.id');

        $this->as($this->preparer)->getJson('/api/v1/admin/pro-commissions?source=demande_devis')->assertJsonPath('data.0.payout.status.value', 'a_payer');
        $this->as($this->preparer)->getJson('/api/v1/admin/contractor-payouts')->assertJsonPath('data.payable', []);

        $this->as($this->preparer)->postJson("/api/v1/admin/contractor-payouts/{$payout}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'Une autre personne que celle qui l’a préparé doit valider ce versement.');

        $this->as($this->approver)->postJson("/api/v1/admin/contractor-payouts/{$payout}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Versement envoyé.')
            ->assertJsonPath('data.status.value', 'paye')
            ->assertJsonPath('data.method.number', '+225 07 •• •• •• 78')
            ->assertJsonPath('data.transactions.0.status.value', 'reussie');

        $this->assertSame('reversement_professionnel', PaymentTransaction::query()->where('contractor_payout_id', $payout)->sole()->purpose->value);
        $this->assertTrue(Notification::query()->where('recipient_id', $this->user->id)->where('event_code', 'payout.paid')->exists());
        $this->as($this->preparer)->postJson('/api/v1/admin/contractor-payouts', ['contractor_id' => $this->contractor->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'Rien à verser à ce professionnel.');
    }

    public function test_a_rejected_payout_can_be_retried_or_cancelled(): void
    {
        $this->asContractor()->postJson("/api/v1/contractor/quote-proposals/{$this->proposal}/visit/done")->assertOk();
        $operator = PaymentOperator::query()->where('code', 'orange_money')->value('id');
        $method = $this->as($this->preparer)->postJson('/api/v1/admin/contractors/'.$this->contractor->id.'/payment-method', ['operator_id' => $operator, 'dial_code' => '225', 'phone_number' => '0712340000'])->json('data.pending.id');
        $this->as($this->approver)->postJson('/api/v1/admin/contractors/'.$this->contractor->id.'/payment-method/'.$method.'/approve')->assertOk();

        $payout = $this->as($this->preparer)->postJson('/api/v1/admin/contractor-payouts', ['contractor_id' => $this->contractor->id])->json('data.id');
        $this->as($this->approver)->postJson("/api/v1/admin/contractor-payouts/{$payout}/approve")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'rejete')
            ->assertJsonPath('data.actions.retry', true);

        $this->as($this->preparer)->postJson('/api/v1/admin/contractor-payouts', ['contractor_id' => $this->contractor->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payout.0', 'Un versement est déjà en cours pour ce professionnel.');

        $this->as($this->preparer)->postJson("/api/v1/admin/contractor-payouts/{$payout}/cancel", ['reason' => 'Numéro erroné, à corriger.'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'annule')
            ->assertJsonPath('data.items', []);

        $this->assertSame(0, ContractorPayout::query()->findOrFail($payout)->items()->count());
        $this->as($this->preparer)->getJson('/api/v1/admin/contractor-payouts')->assertJsonPath('data.payable.0.total', 25000);
    }

    public function test_only_payout_admins_act(): void
    {
        $operations = $this->adminTokenFor(Admin::factory()->role('operations')->create());

        $this->withAccessToken($operations)->getJson('/api/v1/admin/contractor-payouts')->assertForbidden();
        $this->withAccessToken($operations)->postJson('/api/v1/admin/contractor-payouts', ['contractor_id' => $this->contractor->id])->assertForbidden();
    }

    private function adminTokenFor(Admin $admin): string
    {
        return app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken;
    }

    /**
     * @return $this
     */
    private function as(string $token): static
    {
        return $this->withAccessToken($token);
    }
}
