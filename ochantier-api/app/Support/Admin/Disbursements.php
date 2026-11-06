<?php

namespace App\Support\Admin;

use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionStatus;
use App\Enums\TransactionStatusSource;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Support\Payments\PaymentGateway;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Sortie d’argent auprès du prestataire : transaction tracée, envoi, résultat. L’opération passe à son état « envoyé »
 * avant l’appel, pour qu’une interruption ne puisse pas la faire repartir une seconde fois.
 */
class Disbursements
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @param  HasMany<PaymentTransaction, *>  $attempts  Transactions déjà tentées pour cette opération.
     * @param  array<string, mixed>  $link  Rattachement de la transaction (nature, bénéficiaire, opération).
     * @param  callable(): void  $sending  Appelé dans la transaction qui précède l’envoi.
     * @param  callable(bool): void  $done  Appelé dans la transaction qui enregistre le résultat.
     */
    public function send(PaymentMethod $method, int $amount, HasMany $attempts, array $link, string $label, string $key, Admin $admin, callable $sending, callable $done): bool
    {
        $previous = (clone $attempts)->latest('initiated_at')->latest('id')->first();

        $transaction = DB::transaction(function () use ($method, $amount, $attempts, $link, $label, $key, $previous, $admin, $sending): PaymentTransaction {
            $transaction = PaymentTransaction::query()->create([
                'direction' => PaymentTransactionDirection::Decaissement,
                ...$link,
                'reference' => sprintf('PAY-%s-%06d', now()->format('Y'), DB::scalar("SELECT nextval('payment_transaction_reference_seq')")),
                'payer_type' => 'plateforme',
                'payment_method_id' => $method->getKey(),
                'operator_id' => $method->operator_id,
                'provider' => $method->operator->provider,
                'idempotency_key' => sprintf('%s:%d', $key, (clone $attempts)->count() + 1),
                'amount' => $amount,
                'status' => PaymentTransactionStatus::Initiee,
                'retry_of_transaction_id' => $previous?->getKey(),
                'initiated_by_type' => $admin->getMorphClass(),
                'initiated_by_id' => $admin->getKey(),
                'metadata' => ['label' => $label, 'phone' => $method->account_number_masked],
            ]);
            $this->history($transaction, null, PaymentTransactionStatus::Initiee, $admin);
            $sending();

            return $transaction;
        });

        $result = $this->gateway->disburse($method, $amount, $transaction->reference);
        $succeeded = $result->status === PaymentTransactionStatus::Reussie->value;
        $status = $succeeded ? PaymentTransactionStatus::Reussie : PaymentTransactionStatus::Echouee;

        DB::transaction(function () use ($transaction, $result, $status, $succeeded, $admin, $done): void {
            $transaction->forceFill(['status' => $status, 'provider_reference' => $result->providerReference, 'failure_code' => $result->failureCode, 'failure_message' => $result->failureMessage, 'completed_at' => now()])->save();
            $this->history($transaction, PaymentTransactionStatus::Initiee, $status, $admin);
            $done($succeeded);
        });

        return $succeeded;
    }

    private function history(PaymentTransaction $transaction, ?PaymentTransactionStatus $from, PaymentTransactionStatus $to, Admin $admin): void
    {
        $transaction->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'source' => TransactionStatusSource::Admin, 'actor_id' => $admin->getKey()]);
    }
}
