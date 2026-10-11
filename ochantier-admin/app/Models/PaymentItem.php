<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bonus, indemnité ou retenue d’une opération : les retenues ne s’appliquent qu’après approbation humaine.
 */
#[Fillable(['kind', 'type', 'amount', 'motif', 'base', 'proof', 'author', 'approver', 'date', 'status'])]
class PaymentItem extends Model
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
