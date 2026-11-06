<?php

namespace App\Support\Admin;

use App\Enums\DisputeStatus;
use App\Enums\MissionStatus;
use App\Enums\OfferType;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentScheduleType;
use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundOrigin;
use App\Enums\RefundStatus;
use App\Models\Admin;
use App\Models\Dispute;
use App\Models\Mission;
use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Models\PromoterProfile;
use App\Models\Refund;
use App\Models\Setting;
use App\Support\PhoneNumber;
use App\Support\Promoter\PromoterNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remboursements au promoteur. Une personne demande ; une autre valide, ce qui envoie l’argent ; une troisième est
 * exigée à partir du seuil de double validation. Le numéro fait partie de ce qui est validé : le changer efface les
 * validations déjà données.
 */
class Refunds
{
    /**
     * Statuts qui engagent le montant.
     */
    private const COMMITTED = [RefundStatus::Demande, RefundStatus::Approuve, RefundStatus::Execute, RefundStatus::Echoue];

    private const DEFAULT_THRESHOLD = 1000000;

    public function __construct(private Disbursements $disbursements, private PromoterNotifier $notifier) {}

    /**
     * Trop-perçu d’une régularisation, sur le numéro enregistré par défaut s’il en a un.
     */
    public function fromRegularisation(Mission $mission, int $amount, Admin $admin, Request $request): Refund
    {
        return $this->create($mission, $amount, 'Trop-perçu constaté à la fin de la mission.', RefundOrigin::Regularisation, $admin, $request);
    }

    /**
     * Remboursement décidé par l’équipe, une fois le coût réel arrêté : avant, le calcul de fin de mission le
     * refacturerait.
     */
    public function request(Mission $mission, int $amount, string $reason, Admin $admin, Request $request): Refund
    {
        return DB::transaction(function () use ($mission, $amount, $reason, $admin, $request): Refund {
            $mission = Mission::query()->whereKey($mission->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->isSettled($mission)) {
                throw ValidationException::withMessages(['refund' => 'Régularisez d’abord cette mission.']);
            }

            if ($amount > $this->refundable($mission)) {
                throw ValidationException::withMessages(['refund' => 'Ce montant dépasse ce qui peut encore être remboursé.']);
            }

            return $this->create($mission, $amount, $reason, RefundOrigin::Manuel, $admin, $request);
        });
    }

    /**
     * Remboursement décidé par un litige, avec le même plafond et les mêmes validations.
     */
    public function fromDispute(Dispute $dispute, int $amount, Admin $admin, Request $request): Refund
    {
        $mission = Mission::query()->whereKey($dispute->mission_id)->lockForUpdate()->firstOrFail();

        if ($amount > $this->refundable($mission)) {
            throw ValidationException::withMessages(['refund_amount' => 'Ce montant dépasse ce qui peut encore être remboursé ('.$this->refundable($mission).' FCFA).']);
        }

        return tap($this->create($mission, $amount, 'Décision du litige '.$dispute->reference.'.', RefundOrigin::Litige, $admin, $request), fn (Refund $refund) => $refund->update(['dispute_id' => $dispute->getKey()]));
    }

    /**
     * Le coût est arrêté : mission régularisée, ou contrat terminé au montant convenu.
     */
    public function isSettled(Mission $mission): bool
    {
        return $mission->status === MissionStatus::Terminee
            && ($mission->pricingSnapshot->offer_type === OfferType::Contractuelle
                || $mission->paymentSchedules()->where('schedule_type', PaymentScheduleType::Regularisation)->exists());
    }

    /**
     * Ce que le promoteur a réglé, moins ce qui est déjà remboursé ou en cours de l’être.
     */
    public function refundable(Mission $mission): int
    {
        return max(0, (int) $mission->paymentSchedules()->sum('amount_paid') - (int) $mission->refunds()->whereIn('status', self::COMMITTED)->sum('amount'));
    }

    public function threshold(): int
    {
        return (int) (Setting::query()->where('key', 'payments.dual_validation_threshold')->value('value') ?? self::DEFAULT_THRESHOLD);
    }

    public function needsSecondApproval(Refund $refund): bool
    {
        return $refund->amount >= $this->threshold();
    }

    /**
     * Numéros enregistrés par le promoteur sur lesquels un opérateur peut envoyer.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function savedNumbers(Refund $refund): Collection
    {
        return PaymentMethod::query()
            ->where('owner_type', $refund->beneficiary_type)
            ->where('owner_id', $refund->beneficiary_id)
            ->where('status', PaymentMethodStatus::Actif)
            ->whereHas('operator', fn ($query) => $query->where('is_active', true)->where('supports_payout', true))
            ->with('operator')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    public function chooseNumber(Refund $refund, string $methodId, Admin $admin, Request $request): void
    {
        $method = $this->savedNumbers($refund)->firstWhere('id', $methodId) ?? throw ValidationException::withMessages(['refund' => 'Ce numéro n’appartient pas au promoteur.']);

        $this->assign($refund, $method, $admin, $request);
    }

    /**
     * Numéro saisi par l’équipe. Il n’apparaît pas parmi les numéros du promoteur dans l’application.
     */
    public function enterNumber(Refund $refund, PaymentOperator $operator, PhoneNumber $phone, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($refund, $operator, $phone, $admin, $request): void {
            $method = $this->savedNumbers($refund)->first(fn (PaymentMethod $method) => $method->operator_id === $operator->getKey() && $method->account_number_encrypted === $phone->e164());

            $method ??= PaymentMethod::query()->create([
                'owner_type' => $refund->beneficiary_type,
                'owner_id' => $refund->beneficiary_id,
                'method_type' => PaymentMethodType::MobileMoney,
                'operator_id' => $operator->getKey(),
                'account_holder_name' => $this->holder($refund),
                'account_number_encrypted' => $phone->e164(),
                'account_number_masked' => $phone->masked(),
                'is_default' => false,
                'status' => PaymentMethodStatus::Actif,
                'created_by_type' => $admin->getMorphClass(),
                'created_by_id' => $admin->getKey(),
            ]);

            $this->assign($refund, $method, $admin, $request);
        });
    }

    /**
     * Validation : la dernière requise envoie l’argent.
     */
    public function approve(Refund $refund, Admin $admin, Request $request): Refund
    {
        $ready = DB::transaction(function () use ($refund, $admin, $request): bool {
            $refund = $this->lock($refund, RefundStatus::Demande);
            $this->requireOtherPerson($refund, $admin);
            $this->requireNoAppeal($refund);

            if ($refund->approved_by_admin_id === $admin->getKey()) {
                throw ValidationException::withMessages(['refund' => 'Une troisième personne doit valider ce remboursement.']);
            }

            $first = $refund->approved_by_admin_id === null;
            $refund->update([$first ? 'approved_by_admin_id' : 'second_approver_admin_id' => $admin->getKey()]);
            AdminAudit::record($admin, 'payments', 'payments.refund_approved', $request, $refund, null, ['amount' => $refund->amount, 'number' => $refund->paymentMethod->account_number_masked]);

            return ! ($first && $this->needsSecondApproval($refund));
        });

        return $ready ? $this->send($refund->refresh(), $admin) : $refund->refresh();
    }

    public function retry(Refund $refund, Admin $admin, Request $request): Refund
    {
        DB::transaction(function () use ($refund, $admin, $request): void {
            $refund = $this->lock($refund, RefundStatus::Echoue);
            $this->requireOtherPerson($refund, $admin);
            $this->requireNoAppeal($refund);
            AdminAudit::record($admin, 'payments', 'payments.refund_retried', $request, $refund);
        });

        return $this->send($refund->refresh(), $admin);
    }

    public function refuse(Refund $refund, string $reason, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($refund, $reason, $admin, $request): void {
            $refund = $this->lock($refund, RefundStatus::Demande, RefundStatus::Echoue);
            $refund->update(['status' => RefundStatus::Refuse, 'refusal_reason' => $reason]);
            AdminAudit::record($admin, 'payments', 'payments.refund_refused', $request, $refund, null, ['reason' => $reason]);
        });
    }

    /**
     * Un recours peut annuler la décision : l’argent ne part pas avant son issue.
     */
    private function requireNoAppeal(Refund $refund): void
    {
        if ($refund->dispute_id && Dispute::query()->whereKey($refund->dispute_id)->where('status', DisputeStatus::EnRecours)->exists()) {
            throw ValidationException::withMessages(['refund' => 'Un recours est en cours sur le litige à l’origine de ce remboursement.']);
        }
    }

    private function create(Mission $mission, int $amount, string $reason, RefundOrigin $origin, Admin $admin, Request $request): Refund
    {
        $transaction = $this->originalTransaction($mission, $amount);
        $default = PaymentMethod::query()
            ->where('owner_type', $transaction->payer_type)
            ->where('owner_id', $transaction->payer_id)
            ->where('status', PaymentMethodStatus::Actif)
            ->where('is_default', true)
            ->whereHas('operator', fn ($query) => $query->where('is_active', true)->where('supports_payout', true))
            ->first();

        $refund = $mission->refunds()->create([
            'reference' => sprintf('REM-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('refund_reference_seq')")),
            'original_transaction_id' => $transaction->getKey(),
            'beneficiary_type' => $transaction->payer_type,
            'beneficiary_id' => $transaction->payer_id,
            'payment_method_id' => $default?->getKey(),
            'amount' => $amount,
            'reason' => $reason,
            'origin' => $origin,
            'status' => RefundStatus::Demande,
            'requested_by_admin_id' => $admin->getKey(),
        ]);

        AdminAudit::record($admin, 'payments', 'payments.refund_requested', $request, $refund, null, ['amount' => $amount, 'origin' => $origin->value]);

        return $refund;
    }

    /**
     * Paiement de référence : le plus récent qui couvre le montant, sinon le plus élevé.
     */
    private function originalTransaction(Mission $mission, int $amount): PaymentTransaction
    {
        $paid = PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::Reussie)
            ->whereHas('allocations.paymentSchedule', fn ($query) => $query->where('mission_id', $mission->getKey()))
            ->get();

        return $paid->where('amount', '>=', $amount)->sortByDesc('completed_at')->first()
            ?? $paid->sortByDesc('amount')->first()
            ?? throw ValidationException::withMessages(['refund' => 'Aucun paiement à rembourser sur cette mission.']);
    }

    private function assign(Refund $refund, PaymentMethod $method, Admin $admin, Request $request): void
    {
        DB::transaction(function () use ($refund, $method, $admin, $request): void {
            $refund = $this->lock($refund, RefundStatus::Demande, RefundStatus::Echoue);
            $refund->update(['payment_method_id' => $method->getKey(), 'status' => RefundStatus::Demande, 'approved_by_admin_id' => null, 'second_approver_admin_id' => null]);
            AdminAudit::record($admin, 'payments', 'payments.refund_number_changed', $request, $refund, null, ['number' => $method->account_number_masked]);
        });
    }

    private function send(Refund $refund, Admin $admin): Refund
    {
        $method = $refund->paymentMethod()->with('operator')->firstOrFail();
        $mission = $refund->mission()->with('site')->firstOrFail();

        $sent = $this->disbursements->send(
            $method,
            $refund->amount,
            $refund->paymentTransactions(),
            ['direction' => PaymentTransactionDirection::Remboursement, 'purpose' => PaymentTransactionPurpose::Remboursement, 'payee_type' => $refund->beneficiary_type, 'payee_id' => $refund->beneficiary_id, 'refund_id' => $refund->getKey()],
            'Remboursement '.$refund->reference,
            'refund:'.$refund->getKey(),
            $admin,
            fn () => $refund->update(['status' => RefundStatus::Approuve]),
            fn (bool $succeeded) => $refund->update($succeeded ? ['status' => RefundStatus::Execute, 'executed_at' => now()] : ['status' => RefundStatus::Echoue]),
        );

        if ($sent) {
            /** @var PromoterProfile $profile */
            $profile = $mission->site->promoterProfile;
            $this->notifier->refundExecuted($refund->refresh(), $profile, $mission->site);
        }

        return $refund->refresh();
    }

    private function holder(Refund $refund): string
    {
        $user = PromoterProfile::query()->with('user')->findOrFail($refund->beneficiary_id)->user;

        return trim($user->first_name.' '.$user->last_name);
    }

    private function lock(Refund $refund, RefundStatus ...$statuses): Refund
    {
        $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            throw ValidationException::withMessages(['refund' => 'Ce remboursement a déjà été traité.']);
        }

        return $locked;
    }

    /**
     * Ni l’auteur de la demande, ni celui qui a saisi le numéro ne peuvent valider l’envoi.
     */
    private function requireOtherPerson(Refund $refund, Admin $admin): void
    {
        $method = $refund->paymentMethod ?? throw ValidationException::withMessages(['refund' => 'Choisissez d’abord le numéro à rembourser.']);

        if ($refund->requested_by_admin_id === $admin->getKey() || ($method->created_by_type === $admin->getMorphClass() && $method->created_by_id === $admin->getKey())) {
            throw ValidationException::withMessages(['refund' => 'Une autre personne doit valider ce remboursement.']);
        }
    }
}
