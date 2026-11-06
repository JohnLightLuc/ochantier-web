<?php

namespace App\Support\Admin;

use App\Enums\PaymentScheduleType;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PayoutAdjustmentKind;
use App\Enums\PayoutAdjustmentStatus;
use App\Enums\WorkDayStatus;
use App\Enums\WorkerPayoutBatchStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\Admin;
use App\Models\PaymentSchedule;
use App\Models\PayoutAdjustment;
use App\Models\WorkDay;
use App\Models\Worker;
use App\Models\WorkerPayout;
use App\Models\WorkerPayoutBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reversements aux ouvriers, par lots. Une personne prépare le lot, ajuste et le soumet ; une autre le valide, ce qui
 * l’exécute. Une journée reversée est verrouillée : elle ne peut plus être corrigée ni payée deux fois.
 */
class WorkerPayouts
{
    public function __construct(private PayoutDirectory $directory, private PayoutMethods $methods, private Disbursements $disbursements) {}

    /**
     * Prépare un lot avec les lignes choisies dans « À reverser ».
     *
     * @param  list<array{schedule_id: string, assignment_id: string}>  $items
     */
    public function prepare(array $items, Admin $admin, Request $request): WorkerPayoutBatch
    {
        return DB::transaction(function () use ($items, $admin, $request): WorkerPayoutBatch {
            $batch = WorkerPayoutBatch::query()->create([
                'reference' => sprintf('LOT-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('worker_payout_batch_reference_seq')")),
                'status' => WorkerPayoutBatchStatus::Brouillon,
                'results' => (object) [],
                'created_by_admin_id' => $admin->getKey(),
            ]);

            foreach ($items as $item) {
                $this->addPayout($batch, $item['schedule_id'], $item['assignment_id'], $admin);
            }

            $this->refreshTotals($batch);
            AdminAudit::record($admin, 'payouts', 'payouts.batch_prepared', $request, $batch, null, ['payouts' => $batch->payout_count, 'net' => $batch->total_net_amount]);

            return $batch;
        });
    }

    public function submit(WorkerPayoutBatch $batch, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($batch, $admin, $request): void {
            $batch = $this->lock($batch, WorkerPayoutBatchStatus::Brouillon);
            $this->requireSendable($batch);

            if (PayoutAdjustment::query()->where('status', PayoutAdjustmentStatus::Proposee)->whereHas('workerPayout', fn ($query) => $query->where('batch_id', $batch->getKey())->where('status', WorkerPayoutStatus::Provisionne))->exists()) {
                throw ValidationException::withMessages(['payout' => 'Des retenues ou bonus attendent encore une décision.']);
            }

            $batch->update(['status' => WorkerPayoutBatchStatus::AValider]);
            AdminAudit::record($admin, 'payouts', 'payouts.batch_submitted', $request, $batch, null, ['net' => $batch->total_net_amount]);
        });
    }

    /**
     * La validation par une autre personne exécute le lot.
     */
    public function approve(WorkerPayoutBatch $batch, Admin $admin, Request $request): WorkerPayoutBatch
    {
        DB::transaction(function () use ($batch, $admin, $request): void {
            $batch = $this->lock($batch, WorkerPayoutBatchStatus::AValider);
            $this->requireOtherPerson($batch->created_by_admin_id, $admin);
            $this->requireSendable($batch);

            $batch->workerPayouts()->where('status', WorkerPayoutStatus::Provisionne)->update(['status' => WorkerPayoutStatus::Autorise, 'approved_by_admin_id' => $admin->getKey(), 'approved_at' => now()]);
            $batch->update(['status' => WorkerPayoutBatchStatus::EnExecution, 'approved_by_admin_id' => $admin->getKey()]);
            AdminAudit::record($admin, 'payouts', 'payouts.batch_approved', $request, $batch, null, ['net' => $batch->total_net_amount]);
        });

        return $this->run($batch->refresh(), $admin);
    }

    /**
     * Reprend un lot interrompu : seuls les reversements pas encore envoyés partent.
     */
    public function resume(WorkerPayoutBatch $batch, Admin $admin, Request $request): WorkerPayoutBatch
    {
        DB::transaction(function () use ($batch, $admin): void {
            $batch = $this->lock($batch, WorkerPayoutBatchStatus::EnExecution);
            $this->requireOtherPerson($batch->created_by_admin_id, $admin);
        });

        AdminAudit::record($admin, 'payouts', 'payouts.batch_resumed', $request, $batch);

        return $this->run($batch->refresh(), $admin);
    }

    public function reject(WorkerPayoutBatch $batch, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($batch, $reason, $admin, $request): void {
            $batch = $this->lock($batch, WorkerPayoutBatchStatus::AValider);
            $this->requireOtherPerson($batch->created_by_admin_id, $admin);

            $batch->update(['status' => WorkerPayoutBatchStatus::Brouillon]);
            AdminAudit::record($admin, 'payouts', 'payouts.batch_rejected', $request, $batch, null, ['reason' => $reason]);
        });
    }

    public function cancel(WorkerPayoutBatch $batch, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($batch, $admin, $request): void {
            $batch = $this->lock($batch, WorkerPayoutBatchStatus::Brouillon, WorkerPayoutBatchStatus::AValider);

            $batch->workerPayouts()->where('status', WorkerPayoutStatus::Provisionne)->get()->each(fn (WorkerPayout $payout) => $this->release($payout));
            $batch->update(['status' => WorkerPayoutBatchStatus::Annule]);
            $this->refreshTotals($batch);
            AdminAudit::record($admin, 'payouts', 'payouts.batch_cancelled', $request, $batch);
        });
    }

    /**
     * Retire un reversement d’un lot en préparation, ou abandonne un reversement rejeté : ses journées redeviennent
     * à reverser.
     */
    public function cancelPayout(WorkerPayout $payout, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($payout, $reason, $admin, $request): void {
            $batch = WorkerPayoutBatch::query()->whereKey($payout->batch_id)->lockForUpdate()->firstOrFail();
            $payout = WorkerPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            $inDraft = $payout->status === WorkerPayoutStatus::Provisionne && $batch->status === WorkerPayoutBatchStatus::Brouillon;

            if (! $inDraft && $payout->status !== WorkerPayoutStatus::Rejete) {
                throw ValidationException::withMessages(['payout' => 'Ce reversement ne peut plus être retiré.']);
            }

            $this->release($payout);
            $inDraft ? $this->refreshDraft($batch) : $this->finish($batch);
            AdminAudit::record($admin, 'payouts', 'payouts.payout_cancelled', $request, $payout, null, ['reason' => $reason]);
        });
    }

    /**
     * Renvoie un reversement rejeté, sur le numéro confirmé actuel de l’ouvrier.
     */
    public function retry(WorkerPayout $payout, Admin $admin, Request $request): WorkerPayout
    {
        DB::transaction(function () use ($payout, $admin): void {
            $locked = WorkerPayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkerPayoutStatus::Rejete) {
                throw ValidationException::withMessages(['payout' => 'Seul un reversement rejeté peut être renvoyé.']);
            }

            $this->requireOtherPerson($locked->created_by_admin_id, $admin);

            if ($this->directory->hasOpenDispute($locked)) {
                throw ValidationException::withMessages(['payout' => 'Un litige ouvert bloque ce reversement.']);
            }

            $locked->update(['status' => WorkerPayoutStatus::Autorise]);
        });

        $payout->refresh();
        $this->send($payout, $admin);
        $this->finish($payout->batch()->firstOrFail());
        AdminAudit::record($admin, 'payouts', 'payouts.payout_retried', $request, $payout, null, ['status' => $payout->status->value]);

        return $payout;
    }

    /**
     * @param  array{kind: string, amount: int, reason: string, basis?: string|null}  $data
     */
    public function proposeAdjustment(WorkerPayout $payout, array $data, Admin $admin, Request $request): PayoutAdjustment
    {
        return DB::transaction(function () use ($payout, $data, $admin, $request): PayoutAdjustment {
            $this->requireDraft($payout);

            $adjustment = $payout->payoutAdjustments()->create([
                'kind' => $data['kind'],
                'amount' => $data['amount'],
                'reason' => trim($data['reason']),
                'basis' => $data['basis'] ?? null,
                'status' => PayoutAdjustmentStatus::Proposee,
                'created_by_admin_id' => $admin->getKey(),
            ]);

            AdminAudit::record($admin, 'payouts', 'payouts.adjustment_proposed', $request, $payout, null, ['adjustment_id' => $adjustment->id, 'kind' => $data['kind'], 'amount' => $data['amount'], 'reason' => $adjustment->reason]);

            return $adjustment;
        });
    }

    public function approveAdjustment(WorkerPayout $payout, PayoutAdjustment $adjustment, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($payout, $adjustment, $admin, $request): void {
            $batch = $this->requireDraft($payout);
            $adjustment = $this->undecided($payout, $adjustment, $admin);

            $adjustment->update(['status' => PayoutAdjustmentStatus::Approuvee, 'approved_by_admin_id' => $admin->getKey()]);
            $this->applyAdjustments($payout);
            $this->refreshTotals($batch);

            AdminAudit::record($admin, 'payouts', 'payouts.adjustment_approved', $request, $payout, null, ['adjustment_id' => $adjustment->id, 'net' => $payout->net_amount]);
        });
    }

    public function refuseAdjustment(WorkerPayout $payout, PayoutAdjustment $adjustment, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($payout, $adjustment, $reason, $admin, $request): void {
            $this->requireDraft($payout);
            $adjustment = $this->undecided($payout, $adjustment, $admin);

            $adjustment->update(['status' => PayoutAdjustmentStatus::Refusee, 'approved_by_admin_id' => $admin->getKey()]);
            AdminAudit::record($admin, 'payouts', 'payouts.adjustment_refused', $request, $payout, null, ['adjustment_id' => $adjustment->id, 'reason' => $reason]);
        });
    }

    private function addPayout(WorkerPayoutBatch $batch, string $scheduleId, string $assignmentId, Admin $admin): void
    {
        $schedule = PaymentSchedule::query()->findOrFail($scheduleId);
        $days = WorkDay::query()
            ->where('assignment_id', $assignmentId)
            ->where('mission_id', $schedule->mission_id)
            ->where('status', WorkDayStatus::Valide)
            ->where('is_locked', false)
            ->whereDate('work_date', '>=', $schedule->period_start)
            ->whereDate('work_date', '<=', $schedule->period_end)
            ->when($schedule->schedule_type === PaymentScheduleType::Regularisation, fn ($query) => $query->whereRaw(PayoutDirectory::outsidePaidWeek('work_days.mission_id', 'work_days.work_date')))
            ->lockForUpdate()
            ->get();

        $found = $this->directory->payable(['schedule_id' => $scheduleId, 'assignment_id' => $assignmentId])->first();
        $row = $found ? $this->directory->payableRow($found) : null;

        if (! $row || $row['days'] !== $days->count()) {
            throw ValidationException::withMessages(['payout' => 'Une ligne choisie n’est plus à reverser. Rechargez la liste.']);
        }

        if ($row['blockers'] !== []) {
            throw ValidationException::withMessages(['payout' => $row['worker']['full_name'].' : '.$row['blockers'][0].'.']);
        }

        $payout = $batch->workerPayouts()->create([
            'reference' => sprintf('REV-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('worker_payout_reference_seq')")),
            'worker_id' => $row['worker']['id'],
            'mission_id' => $row['mission']['id'],
            'payment_schedule_id' => $scheduleId,
            'period_start' => $row['first_day'],
            'period_end' => $row['last_day'],
            'gross_amount' => $row['gross_amount'],
            'commission_amount' => $row['commission_amount'],
            'net_amount' => $row['net_amount'],
            'status' => WorkerPayoutStatus::Provisionne,
            'created_by_admin_id' => $admin->getKey(),
        ]);

        WorkDay::query()->whereKey($days->modelKeys())->update(['is_locked' => true, 'worker_payout_id' => $payout->getKey(), 'payment_schedule_id' => $scheduleId]);
    }

    /**
     * Le reversement est annulé et ses journées redeviennent à reverser.
     */
    private function release(WorkerPayout $payout): void
    {
        WorkDay::query()->where('worker_payout_id', $payout->getKey())->update(['is_locked' => false, 'worker_payout_id' => null, 'payment_schedule_id' => null]);
        $payout->payoutAdjustments()->where('status', PayoutAdjustmentStatus::Proposee)->update(['status' => PayoutAdjustmentStatus::Annulee]);
        $payout->update(['status' => WorkerPayoutStatus::Annule]);
    }

    private function run(WorkerPayoutBatch $batch, Admin $admin): WorkerPayoutBatch
    {
        $batch->workerPayouts()->where('status', WorkerPayoutStatus::Autorise)->with('worker')->orderBy('reference')->get()
            ->each(fn (WorkerPayout $payout) => $this->send($payout, $admin));

        return $this->finish($batch);
    }

    /**
     * Le reversement passe à « envoyé » avant l’appel : une interruption ne peut pas le faire repartir une seconde fois.
     */
    private function send(WorkerPayout $payout, Admin $admin): void
    {
        $method = $this->methods->current(Worker::query()->findOrFail($payout->worker_id)) ?? throw ValidationException::withMessages(['payout' => 'Cet ouvrier n’a plus de numéro confirmé.']);

        $this->disbursements->send(
            $method,
            $payout->net_amount,
            $payout->paymentTransactions(),
            ['purpose' => PaymentTransactionPurpose::ReversementOuvrier, 'payee_type' => 'worker', 'payee_id' => $payout->worker_id, 'payment_schedule_id' => $payout->payment_schedule_id, 'worker_payout_id' => $payout->getKey()],
            'Reversement '.$payout->reference,
            'reversement:'.$payout->getKey(),
            $admin,
            fn () => $payout->update(['status' => WorkerPayoutStatus::Envoye, 'payment_method_id' => $method->getKey()]),
            fn (bool $succeeded) => $payout->update(['status' => $succeeded ? WorkerPayoutStatus::Confirme : WorkerPayoutStatus::Rejete]),
        );
    }

    /**
     * Résultat du lot, recalculé après l’exécution, un renvoi ou l’abandon d’un reversement.
     */
    private function finish(WorkerPayoutBatch $batch): WorkerPayoutBatch
    {
        $statuses = $batch->workerPayouts()->pluck('status');
        $confirmed = $statuses->filter(fn (WorkerPayoutStatus $status) => $status === WorkerPayoutStatus::Confirme)->count();
        $rejected = $statuses->filter(fn (WorkerPayoutStatus $status) => $status === WorkerPayoutStatus::Rejete)->count();
        $unfinished = $statuses->filter(fn (WorkerPayoutStatus $status) => in_array($status, [WorkerPayoutStatus::Autorise, WorkerPayoutStatus::Envoye], true))->count();

        $batch->forceFill([
            'status' => match (true) {
                $unfinished > 0 => WorkerPayoutBatchStatus::EnExecution,
                $confirmed + $rejected === 0 => WorkerPayoutBatchStatus::Annule,
                $rejected === 0 => WorkerPayoutBatchStatus::Termine,
                $confirmed === 0 => WorkerPayoutBatchStatus::Echoue,
                default => WorkerPayoutBatchStatus::Partiel,
            },
            'results' => ['reussis' => $confirmed, 'echoues' => $rejected, 'bloques' => 0],
            'executed_at' => $batch->executed_at ?? now(),
        ])->save();

        $this->refreshTotals($batch);

        return $batch;
    }

    private function refreshDraft(WorkerPayoutBatch $batch): void
    {
        $this->refreshTotals($batch);

        if ($batch->payout_count === 0) {
            $batch->update(['status' => WorkerPayoutBatchStatus::Annule]);
        }
    }

    private function refreshTotals(WorkerPayoutBatch $batch): void
    {
        $active = $batch->workerPayouts()->where('status', '!=', WorkerPayoutStatus::Annule);

        $batch->forceFill([
            'payout_count' => (clone $active)->count(),
            'total_net_amount' => (int) (clone $active)->sum('net_amount'),
            'total_commission_amount' => (int) (clone $active)->sum('commission_amount'),
        ])->save();
    }

    private function applyAdjustments(WorkerPayout $payout): void
    {
        $approved = $payout->payoutAdjustments()->where('status', PayoutAdjustmentStatus::Approuvee)->get();
        $deduction = (int) $approved->where('kind', PayoutAdjustmentKind::Retenue)->sum('amount');
        $bonus = (int) $approved->where('kind', PayoutAdjustmentKind::Bonus)->sum('amount');
        $net = $payout->gross_amount - $payout->commission_amount - $deduction + $bonus;

        if ($net <= 0) {
            throw ValidationException::withMessages(['payout' => 'La retenue dépasse le montant du reversement.']);
        }

        $payout->update(['deduction_amount' => $deduction, 'bonus_amount' => $bonus, 'net_amount' => $net]);
    }

    private function lock(WorkerPayoutBatch $batch, WorkerPayoutBatchStatus ...$statuses): WorkerPayoutBatch
    {
        $locked = WorkerPayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages(['payout' => 'Ce lot a déjà changé d’état. Rechargez la page.']);
        }

        return $locked;
    }

    /**
     * Retenues et bonus se décident tant que le lot est en préparation.
     */
    private function requireDraft(WorkerPayout $payout): WorkerPayoutBatch
    {
        $batch = $this->lock($payout->batch()->firstOrFail(), WorkerPayoutBatchStatus::Brouillon);

        if ($payout->refresh()->status !== WorkerPayoutStatus::Provisionne) {
            throw ValidationException::withMessages(['payout' => 'Ce reversement ne peut plus être ajusté.']);
        }

        return $batch;
    }

    private function requireOtherPerson(?string $authorId, Admin $admin): void
    {
        if ($authorId === $admin->getKey()) {
            throw ValidationException::withMessages(['payout' => 'Une autre personne doit valider.']);
        }
    }

    /**
     * Le lot doit contenir au moins un reversement, aucun ne doit être visé par un litige ouvert.
     */
    private function requireSendable(WorkerPayoutBatch $batch): void
    {
        $payouts = $batch->workerPayouts()->where('status', WorkerPayoutStatus::Provisionne)->with('worker')->get();

        if ($payouts->isEmpty()) {
            throw ValidationException::withMessages(['payout' => 'Ce lot ne contient aucun reversement.']);
        }

        foreach ($payouts as $payout) {
            if ($this->directory->hasOpenDispute($payout)) {
                throw ValidationException::withMessages(['payout' => sprintf('Un litige ouvert bloque le reversement %s : retirez-le du lot.', $payout->reference)]);
            }
        }
    }

    private function undecided(WorkerPayout $payout, PayoutAdjustment $adjustment, Admin $admin): PayoutAdjustment
    {
        $locked = $payout->payoutAdjustments()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== PayoutAdjustmentStatus::Proposee) {
            throw ValidationException::withMessages(['payout' => 'Cette proposition a déjà été traitée.']);
        }

        if ($locked->created_by_admin_id === $admin->getKey()) {
            throw ValidationException::withMessages(['payout' => 'Une autre personne doit décider de cette proposition.']);
        }

        return $locked;
    }
}
