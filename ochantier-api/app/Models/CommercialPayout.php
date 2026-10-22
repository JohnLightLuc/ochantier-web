<?php

namespace App\Models;

use App\Enums\CommercialPayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paiement des gains d’un commercial sur une période.
 */
class CommercialPayout extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'commercial_id', 'period_start', 'period_end', 'total_amount', 'payment_method_id', 'status',
        'statement_media_id', 'created_by_admin_id', 'approved_by_admin_id', 'paid_at',
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
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => CommercialPayoutStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function statementMedia(): BelongsTo
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

    /**
     * @return HasMany<CommercialEarning, $this>
     */
    public function commercialEarnings(): HasMany
    {
        return $this->hasMany(CommercialEarning::class);
    }

    /**
     * @return HasMany<CommercialAdjustment, $this>
     */
    public function commercialAdjustments(): HasMany
    {
        return $this->hasMany(CommercialAdjustment::class);
    }
}
