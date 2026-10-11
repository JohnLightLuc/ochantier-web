<?php

namespace App\Models;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedAssignmentStatus;
use App\Enums\NeedManualStatus;
use App\Enums\NeedUrgency;
use Carbon\CarbonImmutable;
use Database\Factories\NeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Besoin en main-d’œuvre d’un chantier : une ligne par métier, une offre publiée par ligne,
 * et les ouvriers trouvés (affectations) qui couvrent les postes.
 *
 * Le statut est calculé à partir de la couverture, sauf quand l’administration l’a posé (suspension, annulation, clôture, archivage).
 * `details` conserve la saisie complète du formulaire pour la modification.
 */
#[Fillable([
    'slug', 'reference', 'site_id', 'urgency', 'type', 'source', 'admin', 'district', 'contact', 'contact_phone',
    'starts_on', 'ends_on', 'working_hours', 'working_days', 'justification', 'coverage_deadline', 'follow_up',
    'conditions', 'requirements', 'anomalies', 'manual_status', 'reason', 'promoter_validated', 'deposit_paid',
    'details', 'created_on', 'updated_on', 'parent_reference', 'notified_on', 'payment_reference',
])]
class Need extends Model
{
    /** @use HasFactory<NeedFactory> */
    use HasFactory;

    public const SEARCHING = 'En recherche d’ouvriers';

    public const PARTIAL = 'Partiellement couvert';

    public const EXPIRED = 'Expiré';

    public const REPLACING = 'Comblé · remplacement en cours';

    public const COVERED = 'Couvert · validation des ouvriers';

    public const DEPOSIT = 'Validé · acompte en attente';

    public const DONE = 'Comblé · chantier en cours';

    /**
     * Statuts d’un besoin couvert, suivi dans Affectations & Offres.
     *
     * @var array<int, string>
     */
    public const COVERED_STATUSES = [self::REPLACING, self::COVERED, self::DEPOSIT, self::DONE];

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
            'starts_on' => 'date',
            'ends_on' => 'date',
            'coverage_deadline' => 'date',
            'created_on' => 'date',
            'updated_on' => 'date',
            'notified_on' => 'date',
            'requirements' => 'array',
            'anomalies' => 'array',
            'details' => 'array',
            'promoter_validated' => 'boolean',
            'deposit_paid' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<NeedLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(NeedLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<NeedOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(NeedOffer::class)->orderBy('id');
    }

    /**
     * @return HasMany<NeedAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(NeedAssignment::class)->orderBy('id');
    }

    /**
     * @return HasMany<NeedDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(NeedDocument::class)->orderBy('id');
    }

    /**
     * @return HasMany<NeedActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(NeedActivity::class)->latest('occurred_at')->latest('id');
    }

    /**
     * Date du jour des données de démonstration (config/admin.php).
     */
    public static function referenceDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('admin.needs_reference_date') ?? today());
    }

    /**
     * Libellé de couverture selon le pourcentage de postes pourvus.
     */
    public static function coverageLabel(int $percent): string
    {
        return match (true) {
            $percent <= 0 => 'Non couvert',
            $percent < 30 => 'Faiblement couvert',
            $percent < 70 => 'Partiellement couvert',
            $percent < 100 => 'Presque couvert',
            $percent === 100 => 'Totalement couvert',
            default => 'Surcouvert',
        };
    }

    /**
     * Couverture des postes : demandés, sélectionnés, validés par les ouvriers, restants.
     *
     * @return array{need: int, selected: int, validated: int, contacted: int, toContact: int, refused: int, rest: int, percent: int, label: string, over: bool}
     */
    public function coverage(): array
    {
        $need = (int) $this->lines->sum('quantity');
        $count = fn (NeedAssignmentStatus $status): int => $this->assignments->where('status', $status)->count();
        $selected = $this->assignments->filter(fn (NeedAssignment $a): bool => $a->status->isSelected())->count();
        $percent = $need ? (int) round($selected / $need * 100) : 0;

        return [
            'need' => $need,
            'selected' => $selected,
            'validated' => $count(NeedAssignmentStatus::Validated),
            'contacted' => $count(NeedAssignmentStatus::Contacted),
            'toContact' => $count(NeedAssignmentStatus::ToContact),
            'refused' => $count(NeedAssignmentStatus::Refused),
            'rest' => max(0, $need - $selected),
            'percent' => $percent,
            'label' => self::coverageLabel($percent),
            'over' => $selected > $need,
        ];
    }

    /**
     * Statut affiché : celui posé par l’administration, sinon déduit de la couverture et des offres.
     */
    public function status(): string
    {
        if ($this->manual_status) {
            return $this->manual_status->label();
        }

        $coverage = $this->coverage();
        $full = $coverage['selected'] >= $coverage['need'];
        $live = $this->offers->filter(fn (NeedOffer $o): bool => ! $o->manual_status && $o->stats()['selected'] < $o->line->quantity);

        return match (true) {
            $this->deposit_paid && (! $full || $coverage['validated'] < $coverage['need']) => self::REPLACING,
            $full && $coverage['validated'] < $coverage['need'] => self::COVERED,
            $full && ! $this->deposit_paid => self::DEPOSIT,
            $full => self::DONE,
            $live->isNotEmpty() && $live->every(fn (NeedOffer $o): bool => $o->status() === NeedOffer::EXPIRED) => self::EXPIRED,
            $coverage['selected'] > 0 => self::PARTIAL,
            default => self::SEARCHING,
        };
    }

    /**
     * En attente d’ouvriers : recherche en cours ou couverture partielle.
     */
    public function isPending(): bool
    {
        return in_array($this->status(), [self::SEARCHING, self::PARTIAL], true);
    }

    /**
     * Durée en jours calendaires, bornes incluses.
     */
    public function duration(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->manual_status?->isFinal();
    }

    /**
     * Ajoute un événement au journal du besoin et date la dernière activité.
     */
    public function logActivity(NeedActivityCategory $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, string $origin = 'Back-office', ?string $author = null): NeedActivity
    {
        $this->forceFill(['updated_on' => self::referenceDate()])->save();

        return $this->activities()->create([
            'occurred_at' => now(),
            'author' => $author ?? auth()->user()?->name ?? 'Système',
            'category' => $category,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'comment' => $comment,
            'origin' => $origin,
        ]);
    }

    /**
     * Prochain numéro de besoin (« 0161 » pour BES-2026-0161).
     */
    public static function nextNumber(): string
    {
        $last = static::query()->pluck('reference')->map(fn (string $r): int => (int) substr($r, -4))->max() ?? 140;

        return sprintf('%04d', $last + 1);
    }
}
