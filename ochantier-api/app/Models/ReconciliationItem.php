<?php

namespace App\Models;

use App\Enums\ReconciliationDiscrepancy;
use App\Enums\ReconciliationItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écart relevé lors d’un rapprochement.
 */
#[Fillable([
    'run_id', 'transaction_id', 'provider_reference', 'provider_amount', 'platform_amount', 'discrepancy_type',
    'status', 'resolution', 'resolved_by_admin_id', 'resolved_at',
])]
#[WithoutTimestamps]
class ReconciliationItem extends Model
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
            'discrepancy_type' => ReconciliationDiscrepancy::class,
            'status' => ReconciliationItemStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReconciliationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
