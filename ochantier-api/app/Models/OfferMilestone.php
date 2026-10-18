<?php

namespace App\Models;

use App\Enums\OfferMilestoneStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jalons d'une offre contractuelle (paiement par jalon).
 */
#[Fillable([
    'offer_id', 'sequence', 'label', 'description', 'amount', 'percent_of_total', 'due_date', 'status', 'reached_at',
    'proof_document_id', 'validated_by_admin_id', 'validated_at',
])]
class OfferMilestone extends Model
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
            'percent_of_total' => 'decimal:4',
            'due_date' => 'date',
            'status' => OfferMilestoneStatus::class,
            'reached_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function proofDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function validatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<PaymentSchedule, $this>
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class, 'milestone_id');
    }
}
