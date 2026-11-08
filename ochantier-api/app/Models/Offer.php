<?php

namespace App\Models;

use App\Enums\ContractPaymentMode;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\OfferVisibility;
use App\Enums\PriceOrigin;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Offre promoteur publiée sur un chantier : un ou plusieurs besoins, prix fixé par l'ouvrier ou le promoteur.
 */
class Offer extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'site_id', 'offer_type', 'price_origin', 'title', 'description', 'urgency', 'start_date', 'end_date',
        'contract_total_amount', 'quote_document_id', 'quote_amount', 'deliverables', 'visibility', 'published_at',
        'application_deadline', 'applications_open', 'extension_count', 'auto_renew', 'status', 'submitted_at',
        'assigned_admin_id', 'created_by_user_id', 'cancellation_reason', 'client_uuid', 'lock_version', 'payment_mode',
        'contractor_bid_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offer_type' => OfferType::class,
            'price_origin' => PriceOrigin::class,
            'urgency' => Priority::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'visibility' => OfferVisibility::class,
            'published_at' => 'datetime',
            'application_deadline' => 'datetime',
            'applications_open' => 'boolean',
            'auto_renew' => 'boolean',
            'status' => OfferStatus::class,
            'payment_mode' => ContractPaymentMode::class,
            'submitted_at' => 'datetime',
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
     * @return BelongsTo<Document, $this>
     */
    public function quoteDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OfferStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OfferStatusHistory::class);
    }

    /**
     * @return HasMany<Need, $this>
     */
    public function needs(): HasMany
    {
        return $this->hasMany(Need::class);
    }

    /**
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * @return HasMany<ContractorBid, $this>
     */
    public function contractorBids(): HasMany
    {
        return $this->hasMany(ContractorBid::class);
    }

    /**
     * @return BelongsTo<ContractorBid, $this>
     */
    public function contractorBid(): BelongsTo
    {
        return $this->belongsTo(ContractorBid::class);
    }

    /**
     * @return HasMany<OfferMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(OfferMilestone::class);
    }

    /**
     * @return HasOne<Team, $this>
     */
    public function team(): HasOne
    {
        return $this->hasOne(Team::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /**
     * @return MorphMany<VerificationReview, $this>
     */
    public function verificationReviews(): MorphMany
    {
        return $this->morphMany(VerificationReview::class, 'subject');
    }
}
