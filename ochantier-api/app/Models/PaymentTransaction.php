<?php

namespace App\Models;

use App\Enums\PaymentTransactionDirection;
use App\Enums\PaymentTransactionPurpose;
use App\Enums\PaymentTransactionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Toute opération auprès du prestataire : encaissement, décaissement, remboursement.
 */
#[Fillable([
    'reference', 'direction', 'purpose', 'payer_type', 'payer_id', 'payee_type', 'payee_id', 'payment_schedule_id',
    'worker_payout_id', 'refund_id', 'commercial_payout_id', 'subscription_invoice_id', 'payment_method_id',
    'operator_id', 'provider', 'provider_reference', 'idempotency_key', 'amount', 'fees_amount', 'currency', 'status',
    'failure_code', 'failure_message', 'retry_of_transaction_id', 'initiated_by_type', 'initiated_by_id',
    'initiated_at', 'completed_at', 'reconciled_at', 'metadata',
])]
class PaymentTransaction extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => PaymentTransactionDirection::class,
            'purpose' => PaymentTransactionPurpose::class,
            'status' => PaymentTransactionStatus::class,
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PaymentSchedule, $this>
     */
    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class);
    }

    /**
     * @return BelongsTo<WorkerPayout, $this>
     */
    public function workerPayout(): BelongsTo
    {
        return $this->belongsTo(WorkerPayout::class);
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<CommercialPayout, $this>
     */
    public function commercialPayout(): BelongsTo
    {
        return $this->belongsTo(CommercialPayout::class);
    }

    /**
     * @return BelongsTo<SubscriptionInvoice, $this>
     */
    public function subscriptionInvoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<PaymentOperator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(PaymentOperator::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function retryOfTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * @return HasMany<PaymentTransactionStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(PaymentTransactionStatusHistory::class, 'transaction_id');
    }

    /**
     * @return HasMany<PaymentWebhookEvent, $this>
     */
    public function paymentWebhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class, 'transaction_id');
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'transaction_id');
    }

    /**
     * @return HasMany<ReconciliationItem, $this>
     */
    public function reconciliationItems(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'transaction_id');
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'transaction_id');
    }
}
