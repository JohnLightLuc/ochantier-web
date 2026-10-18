<?php

namespace App\Models;

use App\Enums\ReconciliationRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rapprochement des opérations avec un prestataire de paiement.
 */
#[Fillable([
    'provider', 'period_start', 'period_end', 'status', 'summary', 'run_by_admin_id', 'started_at', 'completed_at',
])]
#[WithoutTimestamps]
class ReconciliationRun extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'status' => ReconciliationRunStatus::class,
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function runByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<ReconciliationItem, $this>
     */
    public function reconciliationItems(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'run_id');
    }
}
