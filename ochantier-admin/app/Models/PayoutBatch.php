<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot de reversements : chaque opération conserve sa référence et son résultat (réussi, échoué, bloqué).
 */
#[Fillable(['reference', 'executed_at', 'author', 'results', 'blocked', 'net', 'commission', 'status'])]
class PayoutBatch extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'results' => 'array',
            'blocked' => 'array',
        ];
    }
}
