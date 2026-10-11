<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cycle de Matching : périmètre, volumes produits et statut du traitement.
 */
#[Fillable(['reference', 'ran_at', 'scope', 'needs', 'workers', 'recommendations', 'selections', 'validations', 'assignments', 'successes', 'failures', 'without_profile', 'excluded', 'warnings', 'errors', 'duration', 'initiator', 'status', 'algorithm', 'zone', 'trade'])]
class MatchingCycle extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
        ];
    }
}
