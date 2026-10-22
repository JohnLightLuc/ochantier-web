<?php

namespace App\Models;

use App\Enums\RatingModerationStatus;
use App\Enums\RatingScope;
use App\Enums\RatingStatus;
use App\Enums\RatingVerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Évaluation d’un ouvrier ou d’une équipe par le promoteur.
 */
class Rating extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'rating_scope', 'mission_id', 'assignment_id', 'worker_id', 'team_id', 'author_user_id', 'overall_score',
        'criteria_scores', 'comment', 'recommends', 'publish_consent', 'verification_status', 'fraud_suspected',
        'response', 'response_at', 'status', 'published_at', 'editable_until', 'version', 'reported_at',
        'moderation_status', 'moderated_by_admin_id', 'moderation_reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating_scope' => RatingScope::class,
            'criteria_scores' => 'array',
            'recommends' => 'boolean',
            'publish_consent' => 'boolean',
            'verification_status' => RatingVerificationStatus::class,
            'fraud_suspected' => 'boolean',
            'response_at' => 'datetime',
            'status' => RatingStatus::class,
            'published_at' => 'datetime',
            'editable_until' => 'datetime',
            'reported_at' => 'datetime',
            'moderation_status' => RatingModerationStatus::class,
        ];
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
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function moderatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<RatingVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RatingVersion::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }
}
