<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\PayoutAdjustment;
use App\Models\WorkDay;
use App\Models\Worker;
use App\Models\WorkerPayout;
use App\Models\WorkerPayoutBatch;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;

/**
 * API du Back Office, module Paiements : numéros Mobile Money, reversements aux ouvriers et lots.
 */
class PayoutAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $preparer;

    private Admin $approver;

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    /**
     * Kouamé et Touré ont une journée validée, Serge est absent. L’échéance de la semaine reste à régler.
     */
    private function validatedDay(): void
    {
        $this->confirmSite();
        $this->onWorkDay();
        $this->point($this->assignmentOf('Kouamé Koffi'))->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'))->assertSuccessful();
        $this->onWorkDay(0, '17:00');
        $this->point($this->assignmentOf('Kouamé Koffi'), 'depart')->assertSuccessful();
        $this->point($this->assignmentOf('Touré Ali'), 'depart')->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/absences", ['assignment_id' => $this->assignmentOf('Serge Aka')->id])->assertSuccessful();
        $this->postJson("/api/v1/promoter/sites/{$this->site->id}/attendance/{$this->startDate->toDateString()}/validate")->assertOk();

        $this->preparer = Admin::factory()->role('finance')->create();
        $this->approver = Admin::factory()->role('finance')->create();
    }

    private function settleWeek(): PaymentSchedule
    {
        $schedule = WorkDay::query()->where('status', 'valide')->firstOrFail()->mission->paymentSchedules()->where('schedule_type', 'hebdomadaire')->orderBy('sequence')->firstOrFail();
        PaymentSchedule::query()->whereIn('mission_id', WorkDay::query()->select('mission_id'))->where('schedule_type', 'hebdomadaire')->where('sequence', 1)->get()
            ->each(fn (PaymentSchedule $schedule) => $schedule->forceFill(['amount_paid' => $schedule->amount_due, 'status' => 'payee'])->save());

        return $schedule->refresh();
    }

    private function number(string $name, string $phone = '0712345678'): PaymentMethod
    {
        $worker = $this->assignmentOf($name)->worker;
        $operator = PaymentOperator::query()->where('code', 'orange_money')->sole();

        $this->as($this->preparer)->postJson("/api/v1/admin/workers/{$worker->id}/payment-method", ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => $phone])->assertCreated();
        $method = $worker->paymentMethods()->whereNull('verified_at')->sole();
        $this->as($this->approver)->postJson("/api/v1/admin/workers/{$worker->id}/payment-method/{$method->id}/approve")->assertOk();

        return $method->refresh();
    }

    /**
     * @return list<array{schedule_id: string, assignment_id: string}>
     */
    private function items(): array
    {
        return collect($this->as($this->preparer)->getJson('/api/v1/admin/payouts/payable?per_page=100')->assertOk()->json('data'))
            ->map(fn (array $row) => ['schedule_id' => $row['schedule_id'], 'assignment_id' => $row['assignment_id']])->all();
    }

    private function prepare(): WorkerPayoutBatch
    {
        $id = $this->as($this->preparer)->postJson('/api/v1/admin/payouts/batches', ['items' => $this->items()])->assertCreated()->json('data.id');

        return WorkerPayoutBatch::query()->findOrFail($id);
    }

    private function approve(WorkerPayoutBatch $batch): TestResponse
    {
        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/submit")->assertOk();

        return $this->as($this->approver)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/approve");
    }

    private function openDispute(): Dispute
    {
        $assignment = $this->assignmentOf('Kouamé Koffi');

        return Dispute::query()->create([
            'reference' => 'LIT-TEST-1',
            'site_id' => $this->site->id,
            'mission_id' => $assignment->mission_id,
            'assignment_id' => $assignment->id,
            'category' => 'paiement',
            'opened_by_type' => 'admin',
            'opened_by_id' => $this->preparer->id,
            'description' => 'Le promoteur conteste la journée.',
            'status' => 'ouvert',
        ]);
    }

    public function test_only_validated_days_of_a_paid_schedule_are_payable(): void
    {
        $this->validatedDay();

        $this->as($this->preparer)->getJson('/api/v1/admin/payouts/payable')->assertOk()->assertJsonPath('meta.total', 0);

        $this->settleWeek();
        $assignment = $this->assignmentOf('Kouamé Koffi');
        $response = $this->getJson('/api/v1/admin/payouts/payable?search=Kouamé')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.days', 1)
            ->assertJsonPath('data.0.net_amount', $assignment->worker_net_daily_rate)
            ->assertJsonPath('data.0.gross_amount', $assignment->promoter_daily_rate)
            ->assertJsonPath('data.0.commission_amount', $assignment->promoter_daily_rate - $assignment->worker_net_daily_rate)
            ->assertJsonPath('data.0.blockers', ['Aucun numéro Mobile Money confirmé']);

        $this->assertSame(2, $response->json('meta.counts.blocked'));
        $this->postJson('/api/v1/admin/payouts/batches', ['items' => $this->items()])->assertJsonValidationErrors(['payout']);
    }

    public function test_a_number_needs_a_second_person(): void
    {
        $this->validatedDay();
        $worker = $this->assignmentOf('Kouamé Koffi')->worker;
        $operator = PaymentOperator::query()->where('code', 'orange_money')->sole();
        $url = "/api/v1/admin/workers/{$worker->id}/payment-method";

        $this->as($this->preparer)->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0512345678'])->assertJsonValidationErrors(['phone_number']);
        $response = $this->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0712345678'])->assertCreated()->assertJsonPath('data.current', null);
        $this->assertStringNotContainsString('0712345678', $response->getContent());
        $id = $response->json('data.pending.id');

        $this->assertFalse($response->json('data.pending.can_decide'));
        $this->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0712345679'])->assertJsonValidationErrors(['payout']);
        $this->postJson("{$url}/{$id}/approve")->assertJsonValidationErrors(['payout']);

        $this->as($this->approver)->getJson('/api/v1/admin/payouts/payment-methods/pending')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.can_decide', true)->assertJsonPath('data.0.owner.id', $worker->id)->assertJsonPath('data.0.owner.type', 'worker');
        $this->postJson("{$url}/{$id}/approve")->assertOk()->assertJsonPath('data.current.id', $id)->assertJsonPath('data.pending', null);
        $this->postJson("{$url}/{$id}/approve")->assertJsonValidationErrors(['payout']);

        // Un nouveau numéro ne remplace l’ancien qu’une fois confirmé ; un refus laisse l’ancien en place.
        $second = $this->as($this->preparer)->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0712345679'])->assertCreated()->assertJsonPath('data.current.id', $id)->json('data.pending.id');
        $this->as($this->approver)->postJson("{$url}/{$second}/reject", ['reason' => 'Numéro non vérifié.'])->assertOk()->assertJsonPath('data.current.id', $id)->assertJsonPath('data.pending', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payouts.method_rejected', 'actor_id' => $this->approver->id]);

        $third = $this->as($this->preparer)->postJson($url, ['operator_id' => $operator->id, 'dial_code' => '225', 'phone_number' => '0712345670'])->assertCreated()->json('data.pending.id');
        $this->as($this->approver)->postJson("{$url}/{$third}/approve")->assertOk()->assertJsonPath('data.current.id', $third);
        $this->assertSame(1, $worker->paymentMethods()->where('status', 'actif')->count());
    }

    public function test_a_batch_is_prepared_by_one_person_and_executed_by_another(): void
    {
        $this->validatedDay();
        $this->settleWeek();
        $this->number('Kouamé Koffi');
        $this->number('Touré Ali');
        $net = $this->assignmentOf('Kouamé Koffi')->worker_net_daily_rate + $this->assignmentOf('Touré Ali')->worker_net_daily_rate;

        $batch = $this->prepare();
        $this->assertSame(2, $batch->payout_count);
        $this->assertSame($net, $batch->total_net_amount);
        $this->assertSame(2, WorkDay::query()->where('is_locked', true)->whereNotNull('worker_payout_id')->count());
        $this->getJson('/api/v1/admin/payouts/payable')->assertJsonPath('meta.total', 0);

        $day = WorkDay::query()->where('is_locked', true)->firstOrFail();
        $this->withAccessToken(app(AdminSessions::class)->open(Admin::factory()->role('operations')->create(), new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken)
            ->postJson("/api/v1/admin/attendance/{$day->id}/corrections", ['worked_minutes' => 300, 'reason' => 'Journée déjà payée.'])->assertJsonValidationErrors(['attendance']);

        $this->as($this->approver)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/approve")->assertJsonValidationErrors(['payout']);
        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/submit")->assertOk()->assertJsonPath('data.status.value', 'a_valider')->assertJsonPath('data.can_approve', false);
        $this->postJson("/api/v1/admin/payouts/batches/{$batch->id}/approve")->assertJsonValidationErrors(['payout']);

        $this->as($this->approver)->getJson("/api/v1/admin/payouts/batches/{$batch->id}")->assertJsonPath('data.can_approve', true);
        $this->postJson("/api/v1/admin/payouts/batches/{$batch->id}/approve")->assertOk()
            ->assertJsonPath('data.status.value', 'termine')
            ->assertJsonPath('data.results.reussis', 2)
            ->assertJsonPath('data.approved_by', trim($this->approver->first_name.' '.$this->approver->last_name));
        $this->postJson("/api/v1/admin/payouts/batches/{$batch->id}/approve")->assertJsonValidationErrors(['payout']);

        $this->assertSame(2, WorkerPayout::query()->where('status', 'confirme')->where('approved_by_admin_id', $this->approver->id)->count());
        $transaction = PaymentTransaction::query()->where('direction', 'decaissement')->orderBy('reference')->firstOrFail();
        $this->assertSame('reversement_ouvrier', $transaction->purpose->value);
        $this->assertSame('reussie', $transaction->status->value);
        $this->assertCount(2, $transaction->statusHistories);

        $payout = $transaction->workerPayout;
        $this->getJson("/api/v1/admin/payouts/{$payout->id}")->assertOk()
            ->assertJsonPath('data.status.value', 'confirme')
            ->assertJsonCount(1, 'data.work_days')
            ->assertJsonPath('data.transactions.0.reference', $transaction->reference)
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_retry', false);

        // Les reversements n’apparaissent pas dans les encaissements, mais dans la fiche de l’ouvrier.
        $this->getJson('/api/v1/admin/payments?per_page=100')->assertOk()->assertJsonMissing(['reference' => $transaction->reference]);
        $this->getJson("/api/v1/admin/workers/{$payout->worker_id}/earnings")->assertOk()->assertJsonPath('meta.totals.paid', $payout->net_amount);

        foreach (['status=confirme', 'search=Kouamé', 'batch_id='.$batch->id, 'worker_id='.$payout->worker_id, 'site_id='.$this->site->id, 'date_from='.today()->toDateString()] as $query) {
            $this->getJson('/api/v1/admin/payouts?'.$query)->assertOk()->assertJsonPath('meta.counts.paid_amount', $net);
        }

        $this->get('/api/v1/admin/payouts/export?batch_id='.$batch->id)->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'payouts.batch_approved')->where('actor_id', $this->approver->id)->exists());
    }

    public function test_a_refused_payment_can_be_sent_again_or_given_up(): void
    {
        $this->validatedDay();
        $this->settleWeek();
        $this->number('Kouamé Koffi', '0712340000');
        $this->number('Touré Ali', '0712340000');
        $batch = $this->prepare();

        $this->approve($batch)->assertOk()->assertJsonPath('data.status.value', 'echoue')->assertJsonPath('data.results.echoues', 2);
        [$first, $second] = WorkerPayout::query()->orderBy('reference')->get()->all();
        $this->assertSame('rejete', $first->status->value);

        // Le renvoi part sur le nouveau numéro confirmé, par une autre personne que celle qui a préparé le lot.
        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/{$first->id}/retry")->assertJsonValidationErrors(['payout']);
        $worker = Worker::query()->findOrFail($first->worker_id);
        $name = $worker->first_name.' '.$worker->last_name;
        $this->number($name, '0712345678');
        $this->as($this->approver)->postJson("/api/v1/admin/payouts/{$first->id}/retry")->assertOk()
            ->assertJsonPath('data.status.value', 'confirme')
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('data.transactions.0.status.value', 'echouee');
        $this->assertSame('partiel', $batch->fresh()->status->value);
        $this->assertNotNull(PaymentTransaction::query()->where('worker_payout_id', $first->id)->whereNotNull('retry_of_transaction_id')->first());

        // L’abandon rend ses journées à reverser et le lot se clôt.
        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/{$second->id}/cancel", ['reason' => 'Numéro à revoir.'])->assertOk()->assertJsonPath('data.status.value', 'annule');
        $this->assertSame('termine', $batch->fresh()->status->value);
        $this->assertSame(1, $batch->fresh()->payout_count);
        $this->assertSame(1, WorkDay::query()->where('is_locked', false)->where('status', 'valide')->count());
        $this->getJson('/api/v1/admin/payouts/payable')->assertJsonPath('meta.total', 1);
    }

    public function test_a_deduction_or_a_bonus_is_decided_by_a_second_person(): void
    {
        $this->validatedDay();
        $this->settleWeek();
        $this->number('Kouamé Koffi');
        $this->number('Touré Ali');
        $batch = $this->prepare();
        $payout = WorkerPayout::query()->orderBy('reference')->firstOrFail();
        $url = "/api/v1/admin/payouts/{$payout->id}/adjustments";

        $id = $this->as($this->preparer)->postJson($url, ['kind' => 'retenue', 'amount' => 1000, 'reason' => 'Matériel perdu.'])->assertOk()->json('data.adjustments.0.id');
        $this->postJson("{$url}/{$id}/approve")->assertJsonValidationErrors(['payout']);
        $this->postJson("/api/v1/admin/payouts/batches/{$batch->id}/submit")->assertJsonValidationErrors(['payout']);

        $this->as($this->approver)->postJson("{$url}/{$id}/approve")->assertOk()
            ->assertJsonPath('data.deduction_amount', 1000)
            ->assertJsonPath('data.net_amount', $payout->net_amount - 1000);
        $this->assertSame($batch->total_net_amount - 1000, $batch->fresh()->total_net_amount);

        $this->postJson($url, ['kind' => 'bonus', 'amount' => 500, 'reason' => 'Prime de ponctualité.'])->assertOk();
        $bonus = PayoutAdjustment::query()->where('kind', 'bonus')->sole()->id;
        $this->as($this->preparer)->postJson("{$url}/{$bonus}/refuse", ['reason' => 'Non prévu au contrat.'])->assertOk()->assertJsonPath('data.bonus_amount', 0);

        $this->postJson($url, ['kind' => 'retenue', 'amount' => 90000000, 'reason' => 'Retenue trop élevée.'])->assertOk();
        $excess = PayoutAdjustment::query()->where('amount', 90000000)->sole()->id;
        $this->as($this->approver)->postJson("{$url}/{$excess}/approve")->assertJsonValidationErrors(['payout']);
        $this->assertSame('proposee', PayoutAdjustment::query()->findOrFail($excess)->status->value);
        $this->postJson("{$url}/{$excess}/refuse", ['reason' => 'Montant incohérent.'])->assertOk();

        $this->approve($batch)->assertOk();
        $this->assertSame($payout->net_amount - 1000, PaymentTransaction::query()->where('worker_payout_id', $payout->id)->sole()->amount);
        $this->as($this->preparer)->postJson($url, ['kind' => 'bonus', 'amount' => 500, 'reason' => 'Trop tard pour cela.'])->assertJsonValidationErrors(['payout']);
    }

    public function test_an_open_dispute_blocks_the_payout(): void
    {
        $this->validatedDay();
        $this->settleWeek();
        $this->number('Kouamé Koffi');
        $this->number('Touré Ali');

        $dispute = $this->openDispute();
        $this->as($this->preparer)->getJson('/api/v1/admin/payouts/payable?state=blocked')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.blockers', ['Litige ouvert']);
        $this->getJson('/api/v1/admin/payouts/payable?state=payable')->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/admin/payouts/batches', ['items' => $this->items()])->assertJsonValidationErrors(['payout']);
        $this->assertSame(0, WorkerPayoutBatch::query()->count());
        $this->assertSame(0, WorkDay::query()->where('is_locked', true)->count());

        // Un litige ouvert après la préparation bloque aussi la validation.
        $dispute->update(['status' => 'resolu']);
        $batch = $this->prepare();
        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/submit")->assertOk();
        $dispute->update(['status' => 'en_instruction']);
        $this->as($this->approver)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/approve")->assertJsonValidationErrors(['payout']);
        $this->assertSame(0, PaymentTransaction::query()->where('direction', 'decaissement')->count());
    }

    public function test_a_batch_can_go_back_be_trimmed_and_cancelled(): void
    {
        $this->validatedDay();
        $this->settleWeek();
        $this->number('Kouamé Koffi');
        $this->number('Touré Ali');
        $batch = $this->prepare();
        [$first, $second] = WorkerPayout::query()->orderBy('reference')->get()->all();

        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/submit")->assertOk();
        $this->postJson("/api/v1/admin/payouts/{$first->id}/cancel", ['reason' => 'À retirer du lot.'])->assertJsonValidationErrors(['payout']);
        $this->as($this->approver)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/reject", ['reason' => 'Lot à revoir.'])->assertOk()->assertJsonPath('data.status.value', 'brouillon');

        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/{$first->id}/cancel", ['reason' => 'À retirer du lot.'])->assertOk();
        $this->assertSame(1, $batch->fresh()->payout_count);
        $this->getJson('/api/v1/admin/payouts/payable')->assertJsonPath('meta.total', 1);

        $this->postJson("/api/v1/admin/payouts/batches/{$batch->id}/cancel")->assertOk()->assertJsonPath('data.status.value', 'annule')->assertJsonPath('data.payout_count', 0);
        $this->assertSame('annule', $second->fresh()->status->value);
        $this->assertSame(0, WorkDay::query()->where('is_locked', true)->count());
        $this->assertSame(0, WorkDay::query()->whereNotNull('worker_payout_id')->count());
        $this->getJson('/api/v1/admin/payouts/payable')->assertJsonPath('meta.total', 2);
        $this->postJson('/api/v1/admin/payouts/batches', ['items' => []])->assertJsonValidationErrors(['items']);
        $this->prepare();
        $this->assertSame(2, WorkDay::query()->where('is_locked', true)->count());
    }

    public function test_an_interrupted_batch_resumes_without_sending_twice(): void
    {
        $this->validatedDay();
        $this->settleWeek();
        $this->number('Kouamé Koffi');
        $this->number('Touré Ali');
        $batch = $this->prepare();
        $this->approve($batch)->assertOk();
        [$first, $second] = WorkerPayout::query()->orderBy('reference')->get()->all();

        $second->paymentTransactions()->each(function (PaymentTransaction $transaction): void {
            $transaction->statusHistories()->delete();
            $transaction->delete();
        });
        $second->update(['status' => 'autorise']);
        DB::table('worker_payout_batches')->where('id', $batch->id)->update(['status' => 'en_execution']);

        $this->as($this->preparer)->postJson("/api/v1/admin/payouts/batches/{$batch->id}/resume")->assertJsonValidationErrors(['payout']);
        $this->as($this->approver)->getJson("/api/v1/admin/payouts/batches/{$batch->id}")->assertJsonPath('data.can_resume', true);
        $this->postJson("/api/v1/admin/payouts/batches/{$batch->id}/resume")->assertOk()->assertJsonPath('data.status.value', 'termine');
        $this->assertSame(1, $first->paymentTransactions()->count());
        $this->assertSame(1, $second->paymentTransactions()->count());
    }

    public function test_the_routes_need_their_permissions(): void
    {
        $this->validatedDay();
        $worker = $this->assignmentOf('Kouamé Koffi')->worker;
        $batch = fake()->uuid();

        $this->as(Admin::factory()->role('operations')->create())->getJson('/api/v1/admin/payouts')->assertForbidden();
        $this->getJson('/api/v1/admin/payouts/payable')->assertForbidden();
        $this->getJson("/api/v1/admin/workers/{$worker->id}/payment-method")->assertForbidden();

        $this->as(Admin::factory()->role('direction')->create())->getJson('/api/v1/admin/payouts')->assertOk();
        $this->postJson('/api/v1/admin/payouts/batches', ['items' => []])->assertForbidden();
        $this->postJson("/api/v1/admin/workers/{$worker->id}/payment-method", [])->assertForbidden();
        $this->postJson("/api/v1/admin/payouts/batches/{$batch}/approve")->assertNotFound();

        $this->as(Admin::factory()->role('lecture_seule')->create())->postJson("/api/v1/admin/payouts/batches/{$batch}/approve")->assertForbidden();
        $this->getJson('/api/v1/admin/payouts/export')->assertForbidden();
    }
}
