<?php

namespace App\Models;

use App\Enums\WorkerPayoutBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot de reversements exécuté en une fois auprès du prestataire.
 */
class WorkerPayoutBatch extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'status', 'payout_count', 'total_net_amount', 'total_commission_amount', 'results',
        'created_by_admin_id', 'approved_by_admin_id', 'executed_at',
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
            'status' => WorkerPayoutBatchStatus::class,
            'results' => 'array',
            'executed_at' => 'datetime',
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
     * @return HasMany<WorkerPayout, $this>
     */
    public function workerPayouts(): HasMany
    {
        return $this->hasMany(WorkerPayout::class, 'batch_id');
    }
}
