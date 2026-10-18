<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transfert de portefeuille entre commerciaux.
 */
#[Fillable([
    'worker_id', 'from_commercial_id', 'to_commercial_id', 'reason', 'effective_at', 'transferred_by_admin_id',
])]
class WorkerCommercialTransfer extends Model
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
            'effective_at' => 'datetime',
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
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function fromCommercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function toCommercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function transferredByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
