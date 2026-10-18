<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recalcul d'un score (nouveau barème ou donnée corrigée).
 */
#[Fillable([
    'suggestion_id', 'old_score', 'new_score', 'old_weight_set_id', 'new_weight_set_id', 'reason',
    'recalculated_by_admin_id',
])]
class SuggestionScoreHistory extends Model
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
            'old_score' => 'decimal:3',
            'new_score' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Suggestion, $this>
     */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(Suggestion::class);
    }

    /**
     * @return BelongsTo<MatchingWeightSet, $this>
     */
    public function oldWeightSet(): BelongsTo
    {
        return $this->belongsTo(MatchingWeightSet::class);
    }

    /**
     * @return BelongsTo<MatchingWeightSet, $this>
     */
    public function newWeightSet(): BelongsTo
    {
        return $this->belongsTo(MatchingWeightSet::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function recalculatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
