<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Classement mensuel des commerciaux.
 */
#[Fillable([
    'period_month', 'criteria', 'closed_at', 'published_at', 'created_by_admin_id',
])]
class CommercialRanking extends Model
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
            'period_month' => 'date',
            'criteria' => 'array',
            'closed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<CommercialRankingEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CommercialRankingEntry::class, 'ranking_id');
    }

    /**
     * @return BelongsToMany<CommercialProfile, $this>
     */
    public function commercials(): BelongsToMany
    {
        return $this->belongsToMany(CommercialProfile::class, 'commercial_ranking_entries', 'ranking_id', 'commercial_id')
            ->withPivot('rank', 'score', 'metrics');
    }
}
