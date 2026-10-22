<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Webhooks persistés avant traitement, traités de façon idempotente.
 */
class PaymentWebhookEvent extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider', 'provider_event_id', 'event_type', 'signature_valid', 'payload', 'received_at', 'processing_status',
        'processed_at', 'attempts', 'error', 'transaction_id',
    ];

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processing_status' => WebhookProcessingStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaymentTransactionStatusHistory, $this>
     */
    public function paymentTransactionStatusHistories(): HasMany
    {
        return $this->hasMany(PaymentTransactionStatusHistory::class, 'webhook_event_id');
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
