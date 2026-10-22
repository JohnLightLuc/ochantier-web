<?php

namespace App\Models;

use App\Enums\MissionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Mission = engagement confirmé, un par besoin, après paiement de l'acompte.
 */
class Mission extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'need_id', 'site_id', 'pricing_snapshot_id', 'status', 'planned_start_date', 'planned_end_date',
        'planned_days', 'actual_start_date', 'actual_end_date', 'terms_consent_id', 'deposit_paid_at', 'confirmed_at',
        'activated_by_admin_id', 'validated_days', 'calendar_progress', 'business_progress', 'closed_at',
        'closed_by_admin_id', 'cancellation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MissionStatus::class,
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'deposit_paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'calendar_progress' => 'decimal:2',
            'business_progress' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<PricingSnapshot, $this>
     */
    public function pricingSnapshot(): BelongsTo
    {
        return $this->belongsTo(PricingSnapshot::class);
    }

    /**
     * @return BelongsTo<Consent, $this>
     */
    public function termsConsent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function activatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function closedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<MissionStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(MissionStatusHistory::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    /**
     * @return HasMany<WorkDay, $this>
     */
    public function workDays(): HasMany
    {
        return $this->hasMany(WorkDay::class);
    }

    /**
     * @return HasMany<ProgressDeclaration, $this>
     */
    public function progressDeclarations(): HasMany
    {
        return $this->hasMany(ProgressDeclaration::class);
    }

    /**
     * @return HasMany<UnavailabilityRequest, $this>
     */
    public function unavailabilityRequests(): HasMany
    {
        return $this->hasMany(UnavailabilityRequest::class);
    }

    /**
     * @return HasMany<Replacement, $this>
     */
    public function replacements(): HasMany
    {
        return $this->hasMany(Replacement::class);
    }

    /**
     * @return HasMany<PaymentSchedule, $this>
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class);
    }

    /**
     * @return HasMany<WorkerPayout, $this>
     */
    public function workerPayouts(): HasMany
    {
        return $this->hasMany(WorkerPayout::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<Rating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * @return HasMany<SiteIncident, $this>
     */
    public function siteIncidents(): HasMany
    {
        return $this->hasMany(SiteIncident::class);
    }

    /**
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }
}
