<?php

namespace App\Models;

use App\Enums\CommercialEarningStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gain d’un commercial pour un ouvrier validé.
 */
#[Fillable([
    'commercial_id', 'rule_id', 'worker_id', 'event_type', 'event_at', 'week_start', 'amount', 'status',
    'pending_reason', 'approved_by_admin_id', 'approved_at', 'commercial_payout_id',
])]
class CommercialEarning extends Model
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
            'event_at' => 'datetime',
            'week_start' => 'date',
            'status' => CommercialEarningStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<CommercialEarningRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommercialEarningRule::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<CommercialPayout, $this>
     */
    public function commercialPayout(): BelongsTo
    {
        return $this->belongsTo(CommercialPayout::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }
}
