<?php

namespace App\Models;

use App\Enums\CommercialAdjustmentKind;
use App\Enums\CommercialAdjustmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prime ou retenue appliquée à un commercial.
 */
class CommercialAdjustment extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'commercial_id', 'kind', 'amount', 'reason', 'granted_on', 'status', 'created_by_admin_id',
        'approved_by_admin_id', 'commercial_payout_id',
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
            'kind' => CommercialAdjustmentKind::class,
            'granted_on' => 'date',
            'status' => CommercialAdjustmentStatus::class,
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
     * @return BelongsTo<CommercialPayout, $this>
     */
    public function commercialPayout(): BelongsTo
    {
        return $this->belongsTo(CommercialPayout::class);
    }
}
