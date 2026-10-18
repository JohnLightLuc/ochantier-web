<?php

namespace App\Models;

use App\Casts\PostgresArray;
use App\Enums\NeedStatus;
use App\Enums\OfferType;
use App\Enums\PaymentFrequency;
use App\Enums\SalaryPeriod;
use App\Enums\SkillLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Besoin métier : une ligne de l'offre (un métier, un effectif, une rémunération).
 */
#[Fillable([
    'reference', 'offer_id', 'site_id', 'offer_type', 'trade_id', 'specialty_id', 'role_label', 'is_team_lead',
    'headcount', 'min_experience_years', 'required_level', 'title', 'description', 'expected_results', 'instructions',
    'equipment_required', 'constraints', 'start_date', 'end_date', 'duration_days', 'work_days', 'work_start_time',
    'work_end_time', 'payment_frequency', 'indicative_daily_budget', 'salary_amount', 'salary_period', 'status',
    'submitted_at', 'published_at', 'assigned_admin_id', 'duplicated_from_need_id', 'created_by_user_id',
    'cancellation_reason', 'client_uuid', 'lock_version',
])]
class Need extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offer_type' => OfferType::class,
            'is_team_lead' => 'boolean',
            'required_level' => SkillLevel::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'work_days' => PostgresArray::class.':int',
            'payment_frequency' => PaymentFrequency::class,
            'salary_period' => SalaryPeriod::class,
            'status' => NeedStatus::class,
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
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
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return BelongsTo<Specialty, $this>
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function duplicatedFromNeed(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<NeedCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(NeedCertification::class);
    }

    /**
     * @return HasMany<NeedStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(NeedStatusHistory::class);
    }

    /**
     * @return HasMany<MatchingRun, $this>
     */
    public function matchingRuns(): HasMany
    {
        return $this->hasMany(MatchingRun::class);
    }

    /**
     * @return HasMany<Suggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    /**
     * @return HasMany<Selection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(Selection::class);
    }

    /**
     * @return HasMany<WorkerReservation, $this>
     */
    public function workerReservations(): HasMany
    {
        return $this->hasMany(WorkerReservation::class);
    }

    /**
     * @return HasMany<MatchingExclusion, $this>
     */
    public function matchingExclusions(): HasMany
    {
        return $this->hasMany(MatchingExclusion::class);
    }

    /**
     * @return HasMany<MatchingDerogation, $this>
     */
    public function matchingDerogations(): HasMany
    {
        return $this->hasMany(MatchingDerogation::class);
    }

    /**
     * @return HasMany<PricingSnapshot, $this>
     */
    public function pricingSnapshots(): HasMany
    {
        return $this->hasMany(PricingSnapshot::class);
    }

    /**
     * @return HasMany<Mission, $this>
     */
    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
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

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'need_skills', 'need_id', 'skill_id')
            ->withPivot('is_required');
    }
}
