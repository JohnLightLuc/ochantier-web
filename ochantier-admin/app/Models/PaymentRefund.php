<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remboursement demandé à un promoteur ; une fois effectué, il réduit la commission nette selon la règle configurée.
 */
#[Fillable(['type', 'amount', 'motif', 'beneficiary', 'responsibility', 'rule', 'proof', 'author', 'approver', 'date', 'status', 'timing', 'commission_impact'])]
class PaymentRefund extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
