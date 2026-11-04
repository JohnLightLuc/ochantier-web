<?php

namespace App\Models;

use App\Casts\PostgresArray;
use App\Enums\AvailabilityStatus;
use App\Enums\Gender;
use App\Enums\WorkerVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ouvrier : sans compte en V1 (user_id nul), compte de l'application Ouvrier en V2.
 */
class Worker extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Canal d’acquisition d’un ouvrier enregistré par le Back Office (sans commission pour le commercial référent).
     */
    public const BACK_OFFICE_CHANNEL = 'back_office';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'user_id', 'origin_commercial_id', 'current_commercial_id', 'prospect_id', 'first_name', 'last_name',
        'birth_date', 'birth_place', 'gender', 'nationality_country_id', 'photo_media_id', 'dial_code', 'phone_number', 'phone_verified_at',
        'secondary_phone', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_dial_code', 'emergency_contact_phone_number',
        'residence_commune_id', 'residence_neighborhood_id', 'residence_address', 'residence_landmark',
        'residence_location', 'mobility_radius_km', 'accepts_long_travel', 'needs_accommodation', 'transport_mode',
        'works_solo', 'works_in_team', 'acquisition_channel', 'estimated_daily_rate', 'estimated_daily_rate_at',
        'accepted_offer_types', 'accepted_payment_frequencies', 'preferred_schedule', 'verification_status',
        'submitted_at', 'validated_at', 'validated_by_admin_id', 'suspended_until', 'availability_status',
        'available_from', 'rating_avg', 'rating_count', 'missions_completed', 'attendance_rate', 'acceptance_rate',
        'replacement_count', 'last_assignment_at', 'merged_into_worker_id', 'identity_mismatch', 'client_uuid',
        'lock_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Gender::class,
            'phone_verified_at' => 'datetime',
            'accepts_long_travel' => 'boolean',
            'needs_accommodation' => 'boolean',
            'works_solo' => 'boolean',
            'works_in_team' => 'boolean',
            'estimated_daily_rate_at' => 'date',
            'accepted_offer_types' => PostgresArray::class,
            'accepted_payment_frequencies' => PostgresArray::class,
            'preferred_schedule' => 'array',
            'verification_status' => WorkerVerificationStatus::class,
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'suspended_until' => 'datetime',
            'availability_status' => AvailabilityStatus::class,
            'available_from' => 'date',
            'rating_avg' => 'decimal:2',
            'attendance_rate' => 'decimal:2',
            'acceptance_rate' => 'decimal:2',
            'last_assignment_at' => 'datetime',
            'identity_mismatch' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function originCommercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function currentCommercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function nationalityCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function photoMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function residenceCommune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return BelongsTo<Neighborhood, $this>
     */
    public function residenceNeighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function validatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function mergedIntoWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return HasMany<WorkerTrade, $this>
     */
    public function workerTrades(): HasMany
    {
        return $this->hasMany(WorkerTrade::class);
    }

    /**
     * @return HasMany<WorkerCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(WorkerCertification::class);
    }

    /**
     * @return HasMany<WorkerReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(WorkerReference::class);
    }

    /**
     * @return HasMany<WorkerPortfolioItem, $this>
     */
    public function portfolioItems(): HasMany
    {
        return $this->hasMany(WorkerPortfolioItem::class);
    }

    /**
     * @return HasMany<WorkerAvailability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(WorkerAvailability::class);
    }

    /**
     * @return HasMany<WorkerStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(WorkerStatusHistory::class);
    }

    /**
     * @return HasMany<WorkerSanction, $this>
     */
    public function sanctions(): HasMany
    {
        return $this->hasMany(WorkerSanction::class);
    }

    /**
     * @return HasMany<WorkerDuplicateCandidate, $this>
     */
    public function duplicateCandidates(): HasMany
    {
        return $this->hasMany(WorkerDuplicateCandidate::class);
    }

    /**
     * @return HasMany<WorkerCommercialTransfer, $this>
     */
    public function commercialTransfers(): HasMany
    {
        return $this->hasMany(WorkerCommercialTransfer::class);
    }

    /**
     * @return HasMany<Suggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    /**
     * @return HasMany<WorkerOfferContact, $this>
     */
    public function offerContacts(): HasMany
    {
        return $this->hasMany(WorkerOfferContact::class);
    }

    /**
     * @return HasMany<WorkerResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(WorkerResponse::class);
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
    public function reservations(): HasMany
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
     * @return HasMany<UnavailabilityRequest, $this>
     */
    public function unavailabilityRequests(): HasMany
    {
        return $this->hasMany(UnavailabilityRequest::class);
    }

    /**
     * @return HasMany<PaymentScheduleLine, $this>
     */
    public function paymentScheduleLines(): HasMany
    {
        return $this->hasMany(PaymentScheduleLine::class);
    }

    /**
     * @return HasMany<WorkerPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(WorkerPayout::class);
    }

    /**
     * @return HasMany<CommercialEarning, $this>
     */
    public function commercialEarnings(): HasMany
    {
        return $this->hasMany(CommercialEarning::class);
    }

    /**
     * @return HasMany<Rating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /**
     * @return MorphMany<PaymentMethod, $this>
     */
    public function paymentMethods(): MorphMany
    {
        return $this->morphMany(PaymentMethod::class, 'owner');
    }

    /**
     * @return MorphMany<VerificationReview, $this>
     */
    public function verificationReviews(): MorphMany
    {
        return $this->morphMany(VerificationReview::class, 'subject');
    }

    /**
     * @return MorphMany<Consent, $this>
     */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'subject');
    }

    /**
     * @return BelongsToMany<Trade, $this>
     */
    public function trades(): BelongsToMany
    {
        return $this->belongsToMany(Trade::class, 'worker_trades', 'worker_id', 'trade_id')
            ->withPivot('id', 'is_primary', 'years_experience', 'verified_years', 'start_year', 'level')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Specialty, $this>
     */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'worker_specialties', 'worker_id', 'specialty_id');
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'worker_skills', 'worker_id', 'skill_id')
            ->withPivot('level', 'comment');
    }

    /**
     * @return BelongsToMany<Commune, $this>
     */
    public function mobilityZones(): BelongsToMany
    {
        return $this->belongsToMany(Commune::class, 'worker_mobility_zones', 'worker_id', 'commune_id');
    }
}
