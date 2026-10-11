<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bonus (montant positif) ou retenue (montant négatif) sur les gains d’un commercial.
 */
#[Fillable(['granted_on', 'type', 'amount', 'reason', 'status'])]
class SalesAgentAdjustment extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_on' => 'date',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesAgent, $this>
     */
    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class);
    }

    public function isDeduction(): bool
    {
        return $this->amount < 0;
    }
}
