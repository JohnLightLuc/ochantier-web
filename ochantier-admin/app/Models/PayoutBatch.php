<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lot de reversements : chaque opération conserve sa référence et son résultat (réussi, échoué, bloqué).
 */
class PayoutBatch extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['reference', 'executed_at', 'author', 'results', 'blocked', 'net', 'commission', 'status'];

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
