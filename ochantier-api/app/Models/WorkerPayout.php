<?php

namespace App\Models;

use App\Enums\WorkerPayoutStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reversement à un ouvrier (ordre transmis au prestataire, double validation).
 */
#[Fillable([
    'reference', 'worker_id', 'mission_id', 'payment_schedule_id', 'batch_id', 'period_start', 'period_end',
    'gross_amount', 'commission_amount', 'deduction_amount', 'bonus_amount', 'net_amount', 'payment_method_id',
    'status', 'created_by_admin_id', 'approved_by_admin_id', 'approved_at',
])]
class WorkerPayout extends Model
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
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => WorkerPayoutStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<PaymentSchedule, $this>
     */
    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class);
    }

    /**
     * @return BelongsTo<WorkerPayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(WorkerPayoutBatch::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
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
     * @return HasMany<PayoutAdjustment, $this>
     */
    public function payoutAdjustments(): HasMany
    {
        return $this->hasMany(PayoutAdjustment::class);
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
