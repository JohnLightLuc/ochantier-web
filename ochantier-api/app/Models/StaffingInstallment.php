<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Échéance hebdomadaire du solde d’une recherche d’effectifs, payée par l’entrepreneur.
 */
class StaffingInstallment extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'staffing_request_id', 'sequence', 'label', 'due_date', 'amount', 'status', 'payment_transaction_id', 'paid_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date',
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffingRequest, $this>
     */
    public function staffingRequest(): BelongsTo
    {
        return $this->belongsTo(StaffingRequest::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
