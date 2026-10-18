<?php

namespace App\Models;

use App\Enums\PaymentScheduleStatus;
use App\Enums\PaymentScheduleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Échéance due par le promoteur ; l'acompte X est déduit des échéances suivantes.
 */
#[Fillable([
    'reference', 'mission_id', 'schedule_type', 'sequence', 'period_start', 'period_end', 'milestone_id',
    'threshold_percent', 'workers_amount', 'promoter_commission_amount', 'deposit_deduction_amount',
    'adjustment_amount', 'amount_due', 'amount_paid', 'due_date', 'status', 'pricing_snapshot_id',
    'calculation_details', 'notified_at', 'last_reminder_at',
])]
class PaymentSchedule extends Model
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
            'schedule_type' => PaymentScheduleType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'threshold_percent' => 'decimal:2',
            'due_date' => 'date',
            'status' => PaymentScheduleStatus::class,
            'calculation_details' => 'array',
            'notified_at' => 'datetime',
            'last_reminder_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkDay, $this>
     */
    public function workDays(): HasMany
    {
        return $this->hasMany(WorkDay::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<OfferMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(OfferMilestone::class);
    }

    /**
     * @return BelongsTo<PricingSnapshot, $this>
     */
    public function pricingSnapshot(): BelongsTo
    {
        return $this->belongsTo(PricingSnapshot::class);
    }

    /**
     * @return HasMany<PaymentScheduleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentScheduleLine::class);
    }

    /**
     * @return HasMany<WorkerPayout, $this>
     */
    public function workerPayouts(): HasMany
    {
        return $this->hasMany(WorkerPayout::class);
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
