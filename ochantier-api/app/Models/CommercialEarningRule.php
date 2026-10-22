<?php

namespace App\Models;

use App\Enums\CommercialEarningEvent;
use App\Enums\EarningCalculation;
use App\Enums\EarningCapPeriod;
use App\Enums\ParameterStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Règle de calcul des gains des commerciaux.
 */
class CommercialEarningRule extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_type', 'calculation', 'amount', 'rate', 'cap_amount', 'cap_period', 'effective_from', 'effective_to',
        'status', 'created_by_admin_id', 'approved_by_admin_id',
    ];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => CommercialEarningEvent::class,
            'calculation' => EarningCalculation::class,
            'rate' => 'decimal:4',
            'cap_period' => EarningCapPeriod::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'status' => ParameterStatus::class,
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
     * @return BelongsTo<Admin, $this>
     */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<CommercialEarning, $this>
     */
    public function commercialEarnings(): HasMany
    {
        return $this->hasMany(CommercialEarning::class, 'rule_id');
    }
}
