<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Mission;
use App\Models\Notification;
use App\Models\PaymentOperator;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\WorkDay;
use App\Support\Admin\MissionRegularisations;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use App\Support\Promoter\SiteClosure;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Paiements : régularisation de fin de mission et remboursements.
 */
class RegularisationAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $preparer;

    private Admin $approver;

    private Admin $third;

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * Le chantier s’arrête après une journée validée pour Kouamé et Touré : Serge est absent, le maçon reste à valider.
     */
    private function closedSite(bool $validate = true): Mission
    {
        $this->confirmSite();
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'))->assertSuccessful();
        $this->onWorkDay(0, '17:00');
        $this->point($this->assignmentOf('Kouamé Koffi'), 'depart')->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'), 'depart')->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])->assertSuccessful();

        if ($validate) {
            $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$this->startDate->toDateString()}/validate")->assertOk();
        }

        $this->preparer = Admin::factory()->role('finance')->create();
        $this->approver = Admin::factory()->role('finance')->create();
        $this->third = Admin::factory()->role('finance')->create();
        app(SiteClosure::class)->close($this->site->refresh(), 'Chantier arrêté.');

        return $this->mission();
    }

    /**
     * La mission des maçons, qui porte les journées travaillées.
     */
    private function mission(): Mission
    {
        return $this->assignmentOf('Kouamé Koffi')->mission;
    }

    private function regularise(Mission $mission): PaymentSchedule
    {
        $this->as($this->preparer)->postJson("/api/v1/admin/regularisations/{$mission->id}")->assertOk();

        return $mission->paymentSchedules()->where('schedule_type', 'regularisation')->sole();
    }

    /**
     * Le promoteur a réglé moins que le coût réel.
     */
    private function underpaid(Mission $mission): int
    {
        $mission->paymentSchedules()->where('schedule_type', 'acompte')->update(['amount_paid' => 10000]);

        return app(MissionRegularisations::class)->figures($mission)['balance'];
    }

    private function number(Refund $refund, Admin $admin, string $phone = '0712345678'): void
    {
        $operator = PaymentOperator::query()->where('code', 'orange_money')->sole();
        $this->as($admin)->postJson("/api/v1/admin/refunds/{$refund->id}/number", ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => $phone])->assertOk();
    }

    public function test_an_overpaid_mission_cancels_what_is_unpaid_and_proposes_a_refund(): void
    {
        $mission = $this->closedSite();
        $snapshot = $mission->pricingSnapshot;
        $rate = (int) $this->assignmentOf('Kouamé Koffi')->promoter_daily_rate + (int) $this->assignmentOf('Touré Ali')->promoter_daily_rate;
        $total = $rate + (int) round($rate * (float) $snapshot->y_rate / 100);
        $paid = $snapshot->deposit_amount;

        $this->as($this->preparer)->getJson('/api/v1/admin/regularisations?state=a_faire&search='.$mission->reference)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.days', 2)
            ->assertJsonPath('data.0.total', $total)
            ->assertJsonPath('data.0.balance', $total - $paid)
            ->assertJsonPath('data.0.blockers', []);

        $schedule = $this->regularise($mission);

        $this->assertSame('payee', $schedule->status->value);
        $this->assertSame(0, $schedule->amount_due);
        $this->assertSame($total - $paid, $schedule->adjustment_amount);
        $this->assertSame(0, $mission->paymentSchedules()->where('schedule_type', 'hebdomadaire')->where('status', '!=', 'annulee')->count());

        $refund = Refund::query()->sole();
        $this->assertSame($paid - $total, $refund->amount);
        $this->assertSame('regularisation', $refund->origin->value);
        $this->assertNull($refund->payment_method_id);

        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->number($refund, $this->third);
        $this->as($this->preparer)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertUnprocessable();
        $this->as($this->third)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertUnprocessable();

        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()
            ->assertJsonPath('data.status.value', 'execute')
            ->assertJsonPath('data.can_approve', false);

        $transaction = PaymentTransaction::query()->where('refund_id', $refund->id)->sole();
        $this->assertSame('remboursement', $transaction->direction->value);
        $this->assertSame('remboursement', $transaction->purpose->value);
        $this->assertSame($refund->amount, $transaction->amount);
        $this->assertTrue(Notification::query()->where('event_code', 'payment.refunded')->exists());

        $this->as($this->preparer)->postJson("/api/v1/admin/regularisations/{$mission->id}")->assertUnprocessable()->assertJsonValidationErrors('regularisation');
    }

    public function test_an_underpaid_mission_owes_a_regularisation_that_frees_the_days_once_paid(): void
    {
        $mission = $this->closedSite();
        $owed = $this->underpaid($mission);
        $this->assertGreaterThan(0, $owed);

        $schedule = $this->regularise($mission);

        $this->assertSame('exigible', $schedule->status->value);
        $this->assertSame($owed, $schedule->amount_due);
        $this->assertSame(0, Refund::query()->count());
        $this->assertTrue(Notification::query()->where('event_code', 'payment.regularisation')->exists());
        $this->as($this->preparer)->getJson('/api/v1/admin/payouts/payable')->assertOk()->assertJsonPath('meta.total', 0);

        $this->actingAsPromoter($this->promoter);
        $row = collect($this->getJson('/api/v1/promoter/payments')->assertOk()->json('data'))->firstWhere('key', 'schedule:'.$schedule->id);
        $this->assertSame('Régularisation de fin de mission', $row['short']);
        $this->postJson('/api/v1/promoter/payments', ['schedule_ids' => [$schedule->id], 'operator_code' => 'orange_money', 'dial_code' => '225', 'phone_number' => '0712345634'])->assertCreated();

        $this->assertSame('payee', $schedule->refresh()->status->value);
        $this->as($this->preparer)->getJson('/api/v1/admin/payouts/payable')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.schedule_id', $schedule->id)
            ->assertJsonPath('data.0.days', 1);
    }

    public function test_a_day_of_a_paid_week_is_not_payable_twice(): void
    {
        $mission = $this->closedSite();
        $week = $mission->paymentSchedules()->where('schedule_type', 'hebdomadaire')->where('sequence', 1)->sole();
        $week->forceFill(['amount_paid' => $week->amount_due, 'status' => 'payee'])->save();

        $this->regularise($mission);

        $rows = $this->as($this->preparer)->getJson('/api/v1/admin/payouts/payable')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame([$week->id], array_values(array_unique(array_column($rows, 'schedule_id'))));
    }

    public function test_pending_work_blocks_the_regularisation_and_a_regularised_day_cannot_be_corrected(): void
    {
        $mission = $this->closedSite(validate: false);

        $this->as($this->preparer)->getJson("/api/v1/admin/regularisations/{$mission->id}")->assertOk()
            ->assertJsonPath('data.blockers.0', '2 journées à valider');
        $this->as($this->preparer)->postJson("/api/v1/admin/regularisations/{$mission->id}")->assertUnprocessable()->assertJsonValidationErrors('regularisation');
        $this->assertSame(0, $mission->paymentSchedules()->where('schedule_type', 'regularisation')->count());

        WorkDay::query()->where('mission_id', $mission->id)->where('status', 'a_valider')->update(['status' => 'rejete']);
        $this->regularise($mission);

        $day = WorkDay::query()->where('mission_id', $mission->id)->where('status', 'rejete')->firstOrFail();
        $this->as(Admin::factory()->role('operations')->create())->postJson("/api/v1/admin/attendance/{$day->id}/corrections", ['status' => 'valide', 'reason' => 'Journée finalement travaillée.'])->assertUnprocessable()->assertJsonValidationErrors('attendance');
    }

    public function test_a_large_refund_needs_two_approvals_and_a_new_number_clears_them(): void
    {
        $mission = $this->closedSite();
        Setting::query()->where('key', 'payments.dual_validation_threshold')->update(['value' => 1000]);
        $this->regularise($mission);
        $refund = Refund::query()->sole();
        $this->number($refund, $this->preparer);

        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()
            ->assertJsonPath('data.status.value', 'demande')
            ->assertJsonPath('data.approvals_required', 2);
        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertUnprocessable();

        $this->number($refund, $this->preparer, '0712345699');
        $this->assertNull($refund->refresh()->approved_by_admin_id);

        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
        $this->as($this->third)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('data.status.value', 'execute');
        $this->assertSame($this->third->id, $refund->refresh()->second_approver_admin_id);
    }

    public function test_a_failed_refund_can_be_retried_or_refused(): void
    {
        $mission = $this->closedSite();
        $this->regularise($mission);
        $refund = Refund::query()->sole();
        $this->number($refund, $this->preparer, '0712340000');

        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('data.status.value', 'echoue');
        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/retry")->assertOk()->assertJsonPath('data.status.value', 'echoue');
        $this->assertSame(2, PaymentTransaction::query()->where('refund_id', $refund->id)->count());

        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/refuse", ['reason' => 'Numéro injoignable.'])->assertOk()
            ->assertJsonPath('data.status.value', 'refuse')
            ->assertJsonPath('data.refusal_reason', 'Numéro injoignable.');
        $this->as($this->approver)->postJson("/api/v1/admin/refunds/{$refund->id}/retry")->assertUnprocessable();
    }

    public function test_a_manual_refund_waits_for_the_regularisation_and_is_capped(): void
    {
        $mission = $this->closedSite();
        $url = "/api/v1/admin/regularisations/{$mission->id}/refunds";

        $this->as($this->preparer)->postJson($url, ['amount' => 1000, 'reason' => 'Geste commercial.'])->assertUnprocessable()->assertJsonValidationErrors('refund');

        $schedule = $this->regularise($mission);
        Refund::query()->sole()->update(['status' => 'refuse']);

        $refundable = $this->as($this->preparer)->getJson("/api/v1/admin/regularisations/{$mission->id}")->assertOk()->json('data.refundable');
        $this->assertSame((int) $mission->paymentSchedules()->sum('amount_paid'), $refundable);
        $this->assertSame('payee', $schedule->status->value);

        $this->as($this->preparer)->postJson($url, ['amount' => $refundable + 1, 'reason' => 'Geste commercial.'])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->as($this->preparer)->postJson($url, ['amount' => 5000, 'reason' => 'Geste commercial.'])->assertCreated()
            ->assertJsonPath('data.refundable', $refundable - 5000);
        $this->assertSame(5000, Refund::query()->where('origin', 'manuel')->sole()->amount);
    }

    public function test_a_contract_keeps_its_agreed_amount_and_the_number_stays_out_of_the_promoter_app(): void
    {
        $mission = $this->closedSite();
        $this->regularise($mission);
        $this->number(Refund::query()->sole(), $this->preparer);

        $this->actingAsPromoter($this->promoter);
        $this->getJson('/api/v1/promoter/payments/options')->assertOk()->assertJsonCount(0, 'data.saved_numbers');

        $other = $this->site->missions()->whereKeyNot($mission->id)->firstOrFail();
        $other->pricingSnapshot->update(['offer_type' => 'contractuelle']);
        $this->as($this->preparer)->postJson("/api/v1/admin/regularisations/{$other->id}")->assertUnprocessable()->assertJsonValidationErrors('regularisation');
        $this->as($this->preparer)->getJson("/api/v1/admin/regularisations/{$other->id}")->assertOk()->assertJsonPath('data.state.value', 'contrat');
    }

    public function test_finance_alone_runs_it_and_support_cannot_see_it(): void
    {
        $mission = $this->closedSite();
        $support = Admin::factory()->role('support')->create();
        $direction = Admin::factory()->role('direction')->create();

        $this->as($support)->getJson('/api/v1/admin/regularisations')->assertForbidden();
        $this->as($direction)->postJson("/api/v1/admin/regularisations/{$mission->id}")->assertForbidden();
        $this->regularise($mission);
        $refund = Refund::query()->sole();
        $this->as($this->preparer)->getJson('/api/v1/admin/refunds/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->as($direction)->getJson("/api/v1/admin/refunds/{$refund->id}")->assertOk()->assertJsonPath('data.reference', $refund->reference);
    }
}
