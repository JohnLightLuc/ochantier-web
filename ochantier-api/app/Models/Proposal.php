<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proposition d’équipe présentée au promoteur pour une offre.
 */
class Proposal extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference', 'offer_id', 'site_id', 'sequence', 'status', 'requested_count', 'proposed_count', 'is_partial',
        'daily_amount', 'days', 'total_amount', 'deposit_amount', 'published_at', 'published_by_admin_id', 'expires_at',
        'decided_at', 'decided_by_user_id', 'refusal_reason_id', 'refusal_comment', 'workers_amount', 'commission_amount',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'is_partial' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
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
     * @return HasMany<Suggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return BelongsTo<ReasonCode, $this>
     */
    public function refusalReason(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class, 'refusal_reason_id');
    }

    public function isPending(): bool
    {
        return $this->status === ProposalStatus::AValider && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
