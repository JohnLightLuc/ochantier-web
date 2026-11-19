<?php

namespace App\Models;

use App\Enums\StaffingRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recherche d’effectifs d’un entrepreneur : besoins par métier, offre d’ouvriers, acompte et solde payés par
 * l’entrepreneur.
 */
class StaffingRequest extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'site_id', 'contractor_id', 'parent_request_id', 'start_date', 'end_date', 'days', 'status', 'deposit_percent',
        'service_fee_percent', 'workers_amount', 'deposit_amount', 'service_fee_amount', 'offered_at', 'decided_at', 'paid_at',
        'deposit_transaction_id', 'created_by_user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StaffingRequestStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'integer',
            'deposit_percent' => 'float',
            'service_fee_percent' => 'float',
            'workers_amount' => 'integer',
            'deposit_amount' => 'integer',
            'service_fee_amount' => 'integer',
            'offered_at' => 'datetime',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<StaffingRequest, $this>
     */
    public function parentRequest(): BelongsTo
    {
        return $this->belongsTo(StaffingRequest::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function depositTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * @return HasMany<StaffingRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StaffingRequestLine::class);
    }

    /**
     * @return HasMany<StaffingInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(StaffingInstallment::class);
    }
}
