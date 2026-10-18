<?php

namespace App\Models;

use App\Enums\PaymentTransactionStatus;
use App\Enums\TransactionStatusSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des statuts d’une transaction de paiement.
 */
#[Fillable([
    'transaction_id', 'from_status', 'to_status', 'source', 'webhook_event_id', 'actor_id',
])]
class PaymentTransactionStatusHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => PaymentTransactionStatus::class,
            'to_status' => PaymentTransactionStatus::class,
            'source' => TransactionStatusSource::class,
        ];
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * @return BelongsTo<PaymentWebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentWebhookEvent::class);
    }
}
