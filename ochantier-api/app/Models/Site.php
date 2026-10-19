<?php

namespace App\Models;

use App\Casts\GeoPoint;
use App\Enums\Priority;
use App\Enums\SiteStatus;
use App\Enums\SiteWorkType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chantier d’un promoteur : localisation, calendrier, statut.
 */
#[Fillable([
    'reference', 'internal_reference', 'promoter_profile_id', 'organization_id', 'created_by_user_id', 'label',
    'description', 'construction_type_id', 'main_image_media_id', 'priority', 'budget_amount', 'address',
    'commune_id', 'neighborhood_id', 'access_instructions', 'location', 'geofence_radius_m', 'planned_start_date',
    'planned_duration_days', 'planned_end_date', 'work_start_time', 'work_end_time', 'status', 'submitted_at',
    'validated_at', 'validated_by_admin_id', 'assigned_admin_id', 'closed_at', 'closed_by_admin_id',
    'cancellation_reason', 'archived_at', 'client_uuid', 'lock_version', 'work_type', 'responsible_user_id', 'offer_draft',
])]
class Site extends Model
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
            'priority' => Priority::class,
            'work_type' => SiteWorkType::class,
            'location' => GeoPoint::class,
            'offer_draft' => 'array',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'status' => SiteStatus::class,
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ConstructionType, $this>
     */
    public function constructionType(): BelongsTo
    {
        return $this->belongsTo(ConstructionType::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function mainImageMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<Commune, $this>
     */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /**
     * @return BelongsTo<Neighborhood, $this>
     */
    public function neighborhood(): BelongsTo
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
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
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
     * @return HasMany<SiteMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(SiteMember::class);
    }

    /**
     * @return HasMany<SiteStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(SiteStatusHistory::class);
    }

    /**
     * @return HasMany<SiteStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SiteStep::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * @return HasMany<Need, $this>
     */
    public function needs(): HasMany
    {
        return $this->hasMany(Need::class);
    }

    /**
     * @return HasMany<Mission, $this>
     */
    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
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
    public function incidents(): HasMany
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

    /**
     * @return MorphMany<VerificationReview, $this>
     */
    public function verificationReviews(): MorphMany
    {
        return $this->morphMany(VerificationReview::class, 'subject');
    }
}
