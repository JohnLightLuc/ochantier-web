<?php

namespace App\Support\Contractor;

use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use App\Enums\TransactionStatusSource;
use App\Models\Contractor;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Support\Payments\PaymentGateway;
use App\Support\PhoneNumber;
use App\Support\Pricing\PricingRules;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paiements Mobile Money de l’entrepreneur (acompte et échéances d’une recherche d’effectifs) : détail avec les
 * frais de l’opérateur, encaissement idempotent, puis règlement de ce qui est payé dès que le paiement réussit.
 */
class ContractorPayments
{
    public function __construct(private PaymentGateway $gateway, private PricingRules $pricing) {}

    public function operator(string $code): PaymentOperator
    {
        return PaymentOperator::query()->where('code', $code)->where('is_active', true)->where('supports_collection', true)->first()
            ?? throw ValidationException::withMessages(['operator_code' => 'Choisissez un opérateur Mobile Money.']);
    }

    /**
     * Détail du montant avec l’opérateur choisi.
     *
     * @param  list<array{label: string, amount: int}>  $lines
     * @return array{lines: list<array{label: string, amount: int}>, amount: int, fees: int, total: int, fee_percent: float}
     */
    public function quote(array $lines, int $amount, PaymentOperator $operator): array
    {
        $percent = (float) $operator->collection_fee_percent;
        $fees = $this->pricing->operatorFee($amount, $percent);

        return [
            'lines' => [...$lines, ['label' => 'Frais opérateur '.$operator->name.' '.rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',').' %', 'amount' => $fees]],
            'amount' => $amount,
            'fees' => $fees,
            'total' => $amount + $fees,
            'fee_percent' => $percent,
        ];
    }

    /**
     * Encaisse le montant ; une même clé d’idempotence renvoie le paiement déjà demandé. $settle règle ce qui est
     * payé, dans la même transaction que le passage du paiement à « réussi ».
     *
     * @param  list<array{label: string, amount: int}>  $lines
     * @param  array<string, mixed>  $metadata
     * @param  Closure(PaymentTransaction): void  $settle
     */
    public function pay(Contractor $contractor, User $user, string $label, array $lines, int $amount, PaymentOperator $operator, PhoneNumber $phone, string $idempotencyKey, array $metadata, Closure $settle): PaymentTransaction
    {
        if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        $quote = $this->quote($lines, $amount, $operator);

        $transaction = PaymentTransaction::query()->create([
            'reference' => sprintf('PAY-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('payment_transaction_reference_seq')")),
            'direction' => PaymentTransactionDirection::Encaissement,
            'purpose' => PaymentTransactionPurpose::Effectifs,
            'payer_type' => $contractor->getMorphClass(),
            'payer_id' => $contractor->getKey(),
            'payee_type' => 'plateforme',
            'operator_id' => $operator->getKey(),
            'provider' => $operator->provider,
            'idempotency_key' => $idempotencyKey,
            'amount' => $quote['amount'],
            'fees_amount' => $quote['fees'],
            'status' => PaymentTransactionStatus::Initiee,
            'initiated_by_type' => $user->getMorphClass(),
            'initiated_by_id' => $user->getKey(),
            'metadata' => [...$metadata, 'label' => $label, 'phone' => $phone->masked(), 'lines' => $quote['lines']],
        ]);
        $this->history($transaction, null, PaymentTransactionStatus::Initiee, $user);

        $result = $this->gateway->collect($operator, $phone, $quote['total'], $transaction->reference);

        if ($result->status !== PaymentTransactionStatus::Reussie->value) {
            $transaction->forceFill(['status' => PaymentTransactionStatus::Echouee, 'provider_reference' => $result->providerReference, 'failure_code' => $result->failureCode, 'failure_message' => $result->failureMessage])->save();
            $this->history($transaction, PaymentTransactionStatus::Initiee, PaymentTransactionStatus::Echouee, $user);

            return $transaction;
        }

        return DB::transaction(function () use ($transaction, $result, $user, $settle): PaymentTransaction {
            $settle($transaction);
            $transaction->forceFill(['status' => PaymentTransactionStatus::Reussie, 'provider_reference' => $result->providerReference, 'completed_at' => now()])->save();
            $this->history($transaction, PaymentTransactionStatus::Initiee, PaymentTransactionStatus::Reussie, $user);

            return $transaction->refresh();
        });
    }

    private function history(PaymentTransaction $transaction, ?PaymentTransactionStatus $from, PaymentTransactionStatus $to, User $user): void
    {
        $transaction->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'source' => TransactionStatusSource::Api, 'actor_id' => $user->getKey()]);
    }
}
