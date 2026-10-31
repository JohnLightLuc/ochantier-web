<?php

namespace App\Models;

use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\NeedUrgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Offre publiée automatiquement pour une ligne de besoin (un métier).
 */
class NeedOffer extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['need_line_id', 'reference', 'title', 'urgency', 'visibility', 'published_on', 'deadline', 'starts_on', 'ends_on', 'applications_open', 'manual_status', 'extensions', 'refreshes', 'auto_renew', 'description', 'benefits', 'last_cycle', 'last_matched_on', 'last_matched_by'];

    public const PUBLISHED = 'Publiée';

    public const PARTIAL = 'Partiellement couverte';

    public const COMPLETE = 'Complète';

    public const EXPIRED = 'Expirée';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urgency' => NeedUrgency::class,
            'manual_status' => NeedManualStatus::class,
            'published_on' => 'date',
            'deadline' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'applications_open' => 'boolean',
            'auto_renew' => 'boolean',
            'last_matched_on' => 'date',
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
     * @return BelongsTo<NeedLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(NeedLine::class, 'need_line_id');
    }

    /**
     * @return HasMany<NeedAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(NeedAssignment::class);
    }

    /**
     * Postes pourvus (ouvriers sélectionnés hors refus) et validés.
     *
     * @return array{selected: int, validated: int, rest: int, percent: int}
     */
    public function stats(): array
    {
        $quantity = $this->line->quantity;
        $selected = $this->assignments->filter(fn (NeedAssignment $a): bool => $a->status->isSelected())->count();

        return [
            'selected' => $selected,
            'validated' => $this->assignments->where('status', NeedAssignmentStatus::Validated)->count(),
            'rest' => max(0, $quantity - $selected),
            'percent' => $quantity ? (int) round($selected / $quantity * 100) : 0,
        ];
    }

    public function status(): string
    {
        $stats = $this->stats();

        return match (true) {
            (bool) $this->manual_status => $this->manual_status->offerLabel(),
            $stats['selected'] >= $this->line->quantity => self::COMPLETE,
            $this->deadline->lessThan(Need::referenceDate()) => self::EXPIRED,
            $stats['selected'] > 0 => self::PARTIAL,
            default => self::PUBLISHED,
        };
    }

    /**
     * Ouverte aux candidatures et pas encore couverte.
     */
    public function isOpen(): bool
    {
        return in_array($this->status(), [self::PUBLISHED, self::PARTIAL], true);
    }

    /**
     * Jours restants avant la date limite de candidature (négatif si dépassée).
     */
    public function daysLeft(): int
    {
        return (int) Need::referenceDate()->diffInDays($this->deadline, false);
    }
}
