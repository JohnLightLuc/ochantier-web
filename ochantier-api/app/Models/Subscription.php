<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Abonnement d'un promoteur ; pour une entreprise, organization_id est renseigné.
 */
class Subscription extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'promoter_profile_id', 'organization_id', 'plan_id', 'previous_plan_id', 'pending_plan_id',
        'pending_effective_at', 'status', 'started_at', 'current_period_start', 'current_period_end', 'next_due_date',
        'auto_renew', 'payment_method_id', 'cancelled_at', 'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pending_effective_at' => 'date',
            'status' => SubscriptionStatus::class,
            'started_at' => 'date',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'next_due_date' => 'date',
            'auto_renew' => 'boolean',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function previousPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /**
     * @return HasMany<SubscriptionStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(SubscriptionStatusHistory::class);
    }

    /**
     * @return HasMany<PricingSnapshot, $this>
     */
    public function pricingSnapshots(): HasMany
    {
        return $this->hasMany(PricingSnapshot::class);
    }
}
