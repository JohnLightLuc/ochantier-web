<?php

namespace App\Models;

use App\Enums\PayoutAdjustmentKind;
use App\Enums\PayoutAdjustmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retenue ou bonus appliqué à un reversement, motivé et approuvé.
 */
class PayoutAdjustment extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'worker_payout_id', 'kind', 'amount', 'reason', 'basis', 'proof_media_id', 'status', 'created_by_admin_id',
        'approved_by_admin_id',
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
            'kind' => PayoutAdjustmentKind::class,
            'status' => PayoutAdjustmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<WorkerPayout, $this>
     */
    public function workerPayout(): BelongsTo
    {
        return $this->belongsTo(WorkerPayout::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function proofMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
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
}
