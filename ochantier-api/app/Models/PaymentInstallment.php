<?php

namespace App\Models;

use App\Enums\PaymentInstallmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versement d’un paiement flexible (abonnés) : acompte ou échéance réglé en 2 ou 3 fois.
 */
class PaymentInstallment extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'group_id', 'promoter_profile_id', 'site_id', 'kind', 'proposal_id', 'schedule_ids', 'label', 'sequence',
        'installments_count', 'amount', 'due_date', 'status', 'transaction_id', 'paid_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedule_ids' => 'array',
            'due_date' => 'date',
            'status' => PaymentInstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PromoterProfile, $this>
     */
    public function promoterProfile(): BelongsTo
    {
        return $this->belongsTo(PromoterProfile::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
