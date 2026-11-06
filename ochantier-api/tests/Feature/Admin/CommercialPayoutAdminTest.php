<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CommercialAdjustment;
use App\Models\CommercialEarning;
use App\Models\CommercialPayout;
use App\Models\CommercialProfile;
use App\Models\Dispute;
use App\Models\Notification;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Models\Worker;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminAccessSeeder;
use Database\Seeders\CommercialOfferSeeder;
use Database\Seeders\PlatformRulesSeeder;
use Tests\Feature\Commercial\CommercialTestCase;

/**
 * API du Back Office, module Paiements : versements mensuels aux commerciaux.
 */
class CommercialPayoutAdminTest extends CommercialTestCase
{
    private Admin $preparer;

    private Admin $approver;

    private CommercialProfile $profile;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AdminAccessSeeder::class, PlatformRulesSeeder::class, CommercialOfferSeeder::class]);
        $this->preparer = Admin::factory()->role('finance')->create();
        $this->approver = Admin::factory()->role('finance')->create();
        $this->profile = $this->commercial->commercialProfile;
        $this->commercial->update(['first_name' => 'Adama', 'last_name' => 'Coulibaly']);
        $this->month = CarbonImmutable::today()->subMonth()->format('Y-m');
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * Une commission confirmée du mois écoulé.
     */
    private function earning(string $phone = '0700000071'): CommercialEarning
    {
        $this->actingAsCommercial($this->commercial);
        $id = $this->readyProspect(['phone_number' => $phone]);
        $this->postJson("/api/v1/commercial/prospects/{$id}/submit")->assertOk();
        $worker = Worker::query()->where('phone_number', $phone)->sole();
        $earning = CommercialEarning::query()->where('worker_id', $worker->id)->sole();
        $earning->update(['status' => 'acquis', 'event_at' => CarbonImmutable::today()->subMonth()->startOfMonth()->addDays(3)]);

        return $earning->refresh();
    }

    private function adjustment(string $kind, int $amount, string $status = 'approuvee'): CommercialAdjustment
    {
        return $this->profile->commercialAdjustments()->create(['kind' => $kind, 'amount' => $amount, 'reason' => 'Ajustement du mois.', 'granted_on' => CarbonImmutable::today()->subMonth()->startOfMonth()->toDateString(), 'status' => $status, 'created_by_admin_id' => $this->preparer->id, 'approved_by_admin_id' => $status === 'proposee' ? null : $this->approver->id]);
    }

    private function number(string $phone = '0712345678'): void
    {
        $operator = PaymentOperator::query()->where('code', 'orange_money')->sole();
        $url = "/api/v1/admin/commercials/{$this->profile->id}/payment-method";
        $id = $this->as($this->preparer)->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => $phone])->assertCreated()->json('data.pending.id');
        $this->as($this->approver)->postJson("{$url}/{$id}/approve")->assertOk();
    }

    private function prepare(): CommercialPayout
    {
        $id = $this->as($this->preparer)->postJson('/api/v1/admin/commercial-payouts', ['commercial_id' => $this->profile->id, 'month' => $this->month])->assertCreated()->json('data.id');

        return CommercialPayout::query()->findOrFail($id);
    }

    public function test_a_payout_includes_bonuses_and_deductions_and_needs_a_second_person(): void
    {
        $earning = $this->earning();
        $bonus = $this->adjustment('prime', 5000);
        $deduction = $this->adjustment('retenue', 2000);
        $this->number();
        $total = $earning->amount + 3000;

        $this->as($this->preparer)->getJson('/api/v1/admin/commercial-payouts/payable?month='.$this->month)->assertOk()
            ->assertJsonPath('meta.counts.ready', 1)
            ->assertJsonPath('meta.counts.ready_amount', $total)
            ->assertJsonPath('data.0.commissions', $earning->amount)
            ->assertJsonPath('data.0.bonuses', 5000)
            ->assertJsonPath('data.0.deductions', 2000)
            ->assertJsonPath('data.0.commercial.full_name', 'Adama Coulibaly');

        $payout = $this->prepare();
        $this->assertSame($total, $payout->total_amount);
        $this->assertSame('a_payer', $payout->status->value);
        $this->getJson('/api/v1/admin/commercial-payouts/payable?month='.$this->month)->assertJsonPath('data', []);
        $this->postJson('/api/v1/admin/commercial-payouts', ['commercial_id' => $this->profile->id, 'month' => $this->month])->assertJsonValidationErrors(['payout']);

        $this->postJson("/api/v1/admin/commercial-payouts/{$payout->id}/approve")->assertJsonValidationErrors(['payout']);
        $this->assertSame('acquis', $earning->fresh()->status->value);

        $this->as($this->approver)->postJson("/api/v1/admin/commercial-payouts/{$payout->id}/approve")->assertOk()
            ->assertJsonPath('data.status.value', 'paye')
            ->assertJsonCount(1, 'data.earnings')
            ->assertJsonCount(2, 'data.adjustments')
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.status.value', 'reussie');
        $this->postJson("/api/v1/admin/commercial-payouts/{$payout->id}/approve")->assertJsonValidationErrors(['payout']);

        $this->assertSame('paye', $earning->fresh()->status->value);
        $this->assertSame('payee', $bonus->fresh()->status->value);
        $this->assertSame('payee', $deduction->fresh()->status->value);
        $transaction = PaymentTransaction::query()->where('commercial_payout_id', $payout->id)->sole();
        $this->assertSame('gain_commercial', $transaction->purpose->value);
        $this->assertSame($total, $transaction->amount);
        $this->assertTrue(Notification::query()->where('event_code', 'payout.paid')->where('recipient_id', $this->commercial->id)->exists());

        foreach (['status=paye', 'search=Coulibaly', 'commercial_id='.$this->profile->id] as $query) {
            $this->getJson('/api/v1/admin/commercial-payouts?'.$query)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.counts.paid_amount', $total);
        }

        $this->get('/api/v1/admin/commercial-payouts/export')->assertOk();
        $this->getJson("/api/v1/admin/commercials/{$this->profile->id}/earnings?month=".$this->month)->assertOk()->assertJsonPath('data.payouts.0.status.value', 'paye');
    }

    public function test_what_blocks_a_payout(): void
    {
        $earning = $this->earning();
        $url = '/api/v1/admin/commercial-payouts';
        $payable = "{$url}/payable?month={$this->month}";
        $body = ['commercial_id' => $this->profile->id, 'month' => $this->month];

        $this->as($this->preparer)->getJson($payable)->assertJsonPath('data.0.blockers', ['Aucun numéro Mobile Money confirmé']);
        $this->postJson($url, $body)->assertJsonValidationErrors(['payout']);
        $this->number();

        $this->adjustment('prime', 1000, 'proposee');
        $this->as($this->preparer)->getJson($payable)->assertJsonPath('data.0.blockers', ['Prime ou retenue à décider']);
        CommercialAdjustment::query()->delete();

        $dispute = Dispute::query()->create(['reference' => 'LIT-TEST-2', 'category' => 'gain_commercial', 'opened_by_type' => 'admin', 'opened_by_id' => $this->preparer->id, 'commercial_earning_id' => $earning->id, 'description' => 'Commission contestée.', 'status' => 'ouvert']);
        $this->getJson($payable)->assertJsonPath('data.0.blockers', ['Litige ouvert sur une commission']);
        $dispute->update(['status' => 'resolu']);

        $this->adjustment('retenue', $earning->amount + 1);
        $this->getJson($payable)->assertJsonPath('data.0.total', -1)->assertJsonPath('data.0.blockers', ['Solde nul ou négatif : reporté sur le prochain versement']);
        $this->postJson($url, $body)->assertJsonValidationErrors(['payout']);
        CommercialAdjustment::query()->delete();

        $this->postJson($url, [...$body, 'month' => now()->format('Y-m')])->assertJsonValidationErrors(['payout']);

        $payout = $this->prepare();
        $dispute->update(['status' => 'en_instruction']);
        $this->as($this->approver)->postJson("{$url}/{$payout->id}/approve")->assertJsonValidationErrors(['payout']);
        $this->assertSame('a_payer', $payout->fresh()->status->value);
    }

    public function test_a_refused_payout_can_be_sent_again_or_cancelled(): void
    {
        $earning = $this->earning();
        $bonus = $this->adjustment('prime', 1000);
        $this->number('0712340000');
        $payout = $this->prepare();
        $url = "/api/v1/admin/commercial-payouts/{$payout->id}";

        $this->as($this->approver)->postJson("{$url}/approve")->assertOk()->assertJsonPath('data.status.value', 'rejete')->assertJsonPath('data.transactions.0.status.value', 'echouee');
        $this->assertSame('acquis', $earning->fresh()->status->value);
        $this->assertFalse(Notification::query()->where('event_code', 'payout.paid')->exists());

        $this->as($this->preparer)->postJson("{$url}/retry")->assertJsonValidationErrors(['payout']);
        $this->number('0712345678');
        $this->as($this->approver)->postJson("{$url}/retry")->assertOk()->assertJsonPath('data.status.value', 'paye')->assertJsonCount(2, 'data.transactions');
        $this->postJson("{$url}/retry")->assertJsonValidationErrors(['payout']);
        $this->postJson("{$url}/cancel", ['reason' => 'Trop tard pour annuler.'])->assertJsonValidationErrors(['payout']);
        $this->assertNotNull(PaymentTransaction::query()->where('commercial_payout_id', $payout->id)->whereNotNull('retry_of_transaction_id')->first());

        $other = $this->earning('0700000072');
        $second = $this->prepare();
        $this->as($this->preparer)->postJson("/api/v1/admin/commercial-payouts/{$second->id}/cancel", ['reason' => 'Versement à revoir.'])->assertOk()->assertJsonPath('data.status.value', 'annule');
        $this->assertNull($other->fresh()->commercial_payout_id);
        $this->assertSame('payee', $bonus->fresh()->status->value);
        $this->getJson('/api/v1/admin/commercial-payouts/payable?month='.$this->month)->assertJsonPath('data.0.commissions', $other->amount);
    }

    public function test_a_commercial_number_needs_a_second_person_and_is_listed_with_the_others(): void
    {
        $operator = PaymentOperator::query()->where('code', 'orange_money')->sole();
        $url = "/api/v1/admin/commercials/{$this->profile->id}/payment-method";

        $id = $this->as($this->preparer)->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0712345678'])->assertCreated()
            ->assertJsonPath('data.default_number.phone_number', '0500000001')
            ->assertJsonPath('data.pending.holder', 'Adama Coulibaly')
            ->json('data.pending.id');
        $this->postJson("{$url}/{$id}/approve")->assertJsonValidationErrors(['payout']);
        $this->as($this->approver)->getJson('/api/v1/admin/payouts/payment-methods/pending')->assertJsonPath('data.0.owner.type', 'commercial')->assertJsonPath('data.0.owner.id', $this->profile->id);
        $this->postJson("{$url}/{$id}/approve")->assertOk()->assertJsonPath('data.current.id', $id);
        $this->assertTrue($this->profile->paymentMethods()->whereKey($id)->whereNotNull('verified_at')->exists());
    }

    public function test_the_simulation_counts_bonuses_and_deductions_too(): void
    {
        $earning = $this->earning();
        $bonus = $this->adjustment('prime', 1000);

        $this->artisan('ochantier:simulate-commercial-payout', ['--month' => $this->month])->assertSuccessful();

        $this->assertSame($earning->amount + 1000, CommercialPayout::query()->sole()->total_amount);
        $this->assertSame('payee', $bonus->fresh()->status->value);
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $id = fake()->uuid();

        $this->as(Admin::factory()->role('operations')->create())->getJson('/api/v1/admin/commercial-payouts')->assertForbidden();
        $this->as(Admin::factory()->role('direction')->create())->getJson('/api/v1/admin/commercial-payouts')->assertOk();
        $this->getJson('/api/v1/admin/commercial-payouts/payable')->assertOk();
        $this->postJson('/api/v1/admin/commercial-payouts', [])->assertForbidden();
        $this->postJson("/api/v1/admin/commercial-payouts/{$id}/approve")->assertNotFound();
        $this->as(Admin::factory()->role('lecture_seule')->create())->postJson("/api/v1/admin/commercial-payouts/{$id}/approve")->assertForbidden();
        $this->getJson('/api/v1/admin/commercial-payouts/export')->assertForbidden();
    }
}
