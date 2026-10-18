<?php

namespace App\Models;

use App\Enums\FinancialPeriodClosingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clôture comptable mensuelle : fige les montants de la période.
 */
#[Fillable([
    'period_month', 'status', 'version', 'snapshot', 'closed_by_admin_id', 'closed_at', 'reopened_by_admin_id',
    'reopened_at', 'reopen_reason',
])]
class FinancialPeriodClosing extends Model
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
            'period_month' => 'date',
            'status' => FinancialPeriodClosingStatus::class,
            'snapshot' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function closedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function reopenedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
