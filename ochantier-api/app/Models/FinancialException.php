<?php

namespace App\Models;

use App\Enums\FinancialExceptionStatus;
use App\Enums\PricingVariable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Dérogations ponctuelles (plafonds, exceptions) avec approbation renforcée.
 */
#[Fillable([
    'parameter_code', 'subject_type', 'subject_id', 'override_value', 'reason', 'valid_from', 'valid_to', 'status',
    'requested_by_admin_id', 'approved_by_admin_id', 'approved_at',
])]
class FinancialException extends Model
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
            'parameter_code' => PricingVariable::class,
            'override_value' => 'decimal:4',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'status' => FinancialExceptionStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
