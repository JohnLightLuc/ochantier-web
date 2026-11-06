<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Database\Seeders\AdminAccessSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Paiements : encaissements, échéances et relance du promoteur.
 */
class PaymentAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private function asAdmin(string $role = 'finance'): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open(Admin::factory()->role($role)->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function nextWeekly(): PaymentSchedule
    {
        return PaymentSchedule::query()->where('schedule_type', 'hebdomadaire')->orderBy('due_date')->orderBy('reference')->firstOrFail();
    }

    public function test_the_list_shows_collections_with_their_totals(): void
    {
        $this->confirmSite();
        $transaction = PaymentTransaction::query()->sole();

        $this->asAdmin()->getJson('/api/v1/admin/payments')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.statuses.reussie', 1)
            ->assertJsonPath('meta.counts.collected', $transaction->amount)
            ->assertJsonPath('meta.counts.fees', $transaction->fees_amount)
            ->assertJsonPath('data.0.reference', $transaction->reference)
            ->assertJsonPath('data.0.purpose.value', 'acompte')
            ->assertJsonPath('data.0.site.id', $this->site->id)
            ->assertJsonPath('data.0.operator', 'Orange Money')
            ->assertJsonPath('data.0.total_amount', $transaction->amount + $transaction->fees_amount);

        foreach (['status=reussie', 'purpose=acompte', 'search=Palmiers', 'search='.$transaction->reference, 'sort=amount', 'sort=status', 'operator_id='.$transaction->operator_id, 'site_id='.$this->site->id, 'date_from='.today()->toDateString().'&date_to='.today()->toDateString()] as $query) {
            $this->getJson('/api/v1/admin/payments?'.$query)->assertOk()->assertJsonPath('meta.total', 1);
        }

        $this->getJson('/api/v1/admin/payments?status=echouee')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/payments?search=inconnu')->assertJsonPath('meta.total', 0);
    }

    public function test_a_failed_collection_is_counted_and_shows_its_reason(): void
    {
        $proposal = $this->acceptProposal();
        $this->postJson('/api/v1/promoter/payments', ['proposal_id' => $proposal->id, 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712340000']);
        $transaction = PaymentTransaction::query()->sole();

        $this->asAdmin()->getJson('/api/v1/admin/payments')->assertJsonPath('meta.counts.failed', 1)->assertJsonPath('meta.counts.collected', 0);
        $this->getJson("/api/v1/admin/payments/{$transaction->id}")->assertOk()
            ->assertJsonPath('data.failure.code', 'solde_insuffisant')
            ->assertJsonPath('data.status.value', 'echouee')
            ->assertJsonPath('data.allocations', []);
    }

    public function test_the_collection_page_shows_settled_schedules_receipt_and_history(): void
    {
        $this->confirmSite();
        $transaction = PaymentTransaction::query()->sole();

        $response = $this->asAdmin()->getJson("/api/v1/admin/payments/{$transaction->id}")->assertOk()
            ->assertJsonPath('data.reference', $transaction->reference)
            ->assertJsonPath('data.history.1.to.value', 'reussie')
            ->assertJsonPath('data.allocations.0.schedule.type.value', 'acompte');

        $this->assertNotEmpty($response->json('data.receipts.0.number'));
        $this->assertStringNotContainsString('0712345634', $response->getContent());
        $this->getJson('/api/v1/admin/payments/'.fake()->uuid())->assertNotFound();
    }

    public function test_schedules_show_their_state_of_the_day(): void
    {
        $this->confirmSite();
        $weekly = PaymentSchedule::query()->where('schedule_type', 'hebdomadaire')->count();
        $deposits = PaymentSchedule::query()->where('schedule_type', 'acompte')->count();

        $this->asAdmin()->getJson('/api/v1/admin/payments/schedules?per_page=100')->assertOk()
            ->assertJsonPath('meta.total', $weekly + $deposits)
            ->assertJsonPath('meta.counts.states.payee', $deposits)
            ->assertJsonPath('meta.counts.states.prevue', $weekly)
            ->assertJsonPath('meta.counts.states.en_retard', 0)
            ->assertJsonPath('meta.counts.paid_amount', (int) PaymentSchedule::query()->sum('amount_paid'));

        $schedule = $this->nextWeekly();
        $this->travelTo($schedule->due_date->copy()->addDay()->setTime(9, 0));
        $this->asAdmin();
        $late = PaymentSchedule::query()->where('schedule_type', 'hebdomadaire')->whereDate('due_date', '<', today())->get();

        $this->getJson('/api/v1/admin/payments/schedules?state=en_retard&per_page=100')->assertOk()
            ->assertJsonPath('meta.total', $late->count())
            ->assertJsonPath('meta.counts.late_amount', (int) $late->sum('amount_due'))
            ->assertJsonPath('data.0.state.value', 'en_retard')
            ->assertJsonPath('data.0.state.label', 'En retard');

        foreach (['type=hebdomadaire', 'search=Palmiers', 'search='.$schedule->reference, 'sort=amount&direction=desc', 'sort=state', 'site_id='.$this->site->id, 'date_from='.today()->subDays(30)->toDateString().'&date_to='.today()->toDateString()] as $query) {
            $this->getJson('/api/v1/admin/payments/schedules?'.$query)->assertOk();
        }

        $this->getJson('/api/v1/admin/payments/schedules?search='.$schedule->reference)->assertJsonPath('meta.total', 1);
    }

    public function test_a_schedule_covered_by_a_flexible_payment_follows_its_next_installment(): void
    {
        $this->confirmSite();
        $schedule = $this->nextWeekly();
        $profile = $this->site->promoterProfile;
        $installmentDue = $schedule->due_date->copy()->addDays(20)->toDateString();
        DB::table('payment_installments')->insert([
            'id' => fake()->uuid(), 'group_id' => fake()->uuid(), 'promoter_profile_id' => $profile->id, 'site_id' => $this->site->id, 'kind' => 'echeance', 'schedule_ids' => json_encode([$schedule->id]),
            'label' => 'Versement', 'sequence' => 2, 'installments_count' => 2, 'amount' => 1000, 'due_date' => $installmentDue, 'status' => 'a_payer',
        ]);
        $this->travelTo($schedule->due_date->copy()->addDay()->setTime(9, 0));

        $this->asAdmin()->getJson('/api/v1/admin/payments/schedules/'.$schedule->id)->assertOk()
            ->assertJsonPath('data.state.value', 'prevue')
            ->assertJsonPath('data.due_date', $installmentDue)
            ->assertJsonPath('data.is_flexible', true)
            ->assertJsonPath('data.installments.0.sequence', 2)
            ->assertJsonPath('data.installments.0.is_open', true);
    }

    public function test_the_schedule_page_details_the_amount_and_the_days_of_the_period(): void
    {
        $this->confirmSite();
        $deposit = PaymentSchedule::query()->where('schedule_type', 'acompte')->firstOrFail();
        $schedule = $this->nextWeekly();

        $this->asAdmin()->getJson("/api/v1/admin/payments/schedules/{$schedule->id}")->assertOk()
            ->assertJsonPath('data.workers_amount', $schedule->workers_amount)
            ->assertJsonPath('data.promoter_commission_amount', $schedule->promoter_commission_amount)
            ->assertJsonPath('data.deposit_deduction_amount', $schedule->deposit_deduction_amount)
            ->assertJsonPath('data.days.validated', 0)
            ->assertJsonPath('data.payments', [])
            ->assertJsonPath('data.can.remind', false);

        $this->getJson("/api/v1/admin/payments/schedules/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.days', null)
            ->assertJsonPath('data.payments.0.transaction.status.value', 'reussie');
    }

    public function test_a_due_schedule_can_be_reminded_once_a_day(): void
    {
        $this->confirmSite();
        $schedule = $this->nextWeekly();

        $this->asAdmin()->postJson("/api/v1/admin/payments/schedules/{$schedule->id}/remind")->assertUnprocessable()->assertJsonValidationErrors('payment');

        $this->travelTo($schedule->due_date->copy()->addDay()->setTime(9, 0));
        $this->asAdmin()->postJson("/api/v1/admin/payments/schedules/{$schedule->id}/remind")->assertOk()
            ->assertJsonPath('message', 'Promoteur relancé.');

        $this->assertSame(1, Notification::query()->where('event_code', 'payment.late')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'payments.reminded')->where('subject_id', $schedule->id)->exists());
        $this->assertNotNull($schedule->refresh()->last_reminder_at);

        $this->postJson("/api/v1/admin/payments/schedules/{$schedule->id}/remind")->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertSame(1, Notification::query()->where('event_code', 'payment.late')->count());
    }

    public function test_exports_are_audited(): void
    {
        $this->confirmSite();
        $transaction = PaymentTransaction::query()->sole();

        $collections = $this->asAdmin()->get('/api/v1/admin/payments/export')->assertOk();
        $this->assertStringContainsString($transaction->reference, $collections->streamedContent());

        $schedules = $this->get('/api/v1/admin/payments/schedules/export?type=acompte')->assertOk();
        $this->assertStringContainsString('Acompte', $schedules->streamedContent());
        $this->assertSame(2, AuditLog::query()->whereIn('action', ['payments.exported', 'payments.schedules_exported'])->count());
    }

    public function test_permissions_are_enforced(): void
    {
        $this->confirmSite();
        $schedule = $this->nextWeekly();

        $this->asAdmin('operations')->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->getJson('/api/v1/admin/payments/schedules')->assertForbidden();
        $this->postJson("/api/v1/admin/payments/schedules/{$schedule->id}/remind")->assertForbidden();
    }

    public function test_unknown_filters_are_refused(): void
    {
        $this->seed(AdminAccessSeeder::class);
        $this->asAdmin()->getJson('/api/v1/admin/payments?status=inconnu')->assertUnprocessable();
        $this->getJson('/api/v1/admin/payments/schedules?state=inconnu')->assertUnprocessable();
    }
}
