<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Recalcul du score d’un match ; l’ancien score est conservé.
 */
#[Fillable(['match_reference', 'recalculated_at', 'old_score', 'new_score', 'reason', 'old_weights', 'new_weights', 'author', 'algorithm'])]
class MatchingRecalculation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recalculated_at' => 'datetime',
        ];
    }
}
