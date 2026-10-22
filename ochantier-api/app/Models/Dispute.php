<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Litige entre parties, instruit par le Back Office.
 */
class Dispute extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'category', 'opened_by_type', 'opened_by_id', 'site_id', 'mission_id', 'assignment_id',
        'transaction_id', 'rating_id', 'commercial_earning_id', 'description', 'urgency', 'status', 'assigned_admin_id',
        'decision', 'financial_impact', 'decided_by_admin_id', 'decided_at', 'appeal_of_dispute_id', 'appeal_deadline',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urgency' => Priority::class,
            'status' => DisputeStatus::class,
            'financial_impact' => 'array',
            'decided_at' => 'datetime',
            'appeal_deadline' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /**
     * @return BelongsTo<Rating, $this>
     */
    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    /**
     * @return BelongsTo<CommercialEarning, $this>
     */
    public function commercialEarning(): BelongsTo
    {
        return $this->belongsTo(CommercialEarning::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function decidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Dispute, $this>
     */
    public function appealOfDispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    /**
     * @return HasMany<DisputeParty, $this>
     */
    public function parties(): HasMany
    {
        return $this->hasMany(DisputeParty::class);
    }

    /**
     * @return HasMany<DisputeMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class);
    }

    /**
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function openedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
