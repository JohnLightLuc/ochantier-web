<?php

namespace App\Models;

use App\Enums\VerificationReviewStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cycle de contrôle générique : ouvrier, promoteur, organisation, chantier, besoin.
 */
#[Fillable([
    'subject_type', 'subject_id', 'submission_number', 'submitted_by_type', 'submitted_by_id', 'submitted_at',
    'assigned_admin_id', 'status', 'checklist', 'snapshot', 'decision_reason', 'decided_by_admin_id', 'decided_at',
])]
class VerificationReview extends Model
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
            'submitted_at' => 'datetime',
            'status' => VerificationReviewStatus::class,
            'checklist' => 'array',
            'snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
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
     * @return HasMany<VerificationCorrection, $this>
     */
    public function verificationCorrections(): HasMany
    {
        return $this->hasMany(VerificationCorrection::class, 'review_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function submittedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
