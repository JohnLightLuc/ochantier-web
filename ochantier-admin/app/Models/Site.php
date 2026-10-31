<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\SiteActivityCategory;
use App\Enums\SiteDocumentType;
use App\Enums\SiteIncidentStatus;
use App\Enums\SitePriority;
use App\Enums\SiteStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Chantier publié par un promoteur : planning, effectif, avancement, incidents et documents.
 *
 * Le promoteur peut ne pas avoir de compte (`promoter_id` nul) : son nom est conservé dans `promoter_name`.
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug', 'reference', 'name', 'promoter_id', 'promoter_name', 'commune', 'city', 'address', 'latitude', 'longitude',
        'type', 'category', 'manager', 'manager_phone', 'starts_on', 'ends_on', 'planned_days', 'progress',
        'required_workers', 'assigned_workers', 'present_workers', 'status', 'priority', 'description',
        'working_hours', 'working_days', 'instructions', 'access_conditions', 'safety_equipment',
        'budget', 'engaged_amount', 'paid_amount', 'photos', 'correction_request', 'submitted_on',
    ];

    /**
     * Coordonnées approximatives des communes (latitude, longitude).
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const COORDINATES = [
        'Cocody' => [5.36, -3.97], 'Plateau' => [5.32, -4.02], 'Yopougon' => [5.34, -4.08], 'Bingerville' => [5.355, -3.89], 'Marcory' => [5.30, -3.98],
        'Koumassi' => [5.29, -3.95], 'Port-Bouët' => [5.25, -3.93], 'Treichville' => [5.30, -4.01], 'Abobo' => [5.42, -4.02], 'Adjamé' => [5.36, -4.03],
        'Anyama' => [5.49, -4.05], 'Songon' => [5.32, -4.22], 'Grand-Bassam' => [5.21, -3.74], 'Vridi' => [5.26, -4.00], 'Yamoussoukro' => [6.82, -5.28],
    ];

    /**
     * Phases du chantier et seuil d’avancement (%) qui marque leur achèvement.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    public const MILESTONES = [['Installation de chantier', 5], ['Terrassement et fondations', 20], ['Gros œuvre', 55], ['Second œuvre', 85], ['Finitions et réception', 100]];

    /**
     * Consignes par défaut d’un nouveau chantier.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'working_hours' => '07:30 – 17:00 · pause 12:00 – 13:00',
        'working_days' => 'Lundi au samedi',
        'instructions' => 'Port des EPI obligatoire. Pointage à l’arrivée et au départ via l’application.',
        'access_conditions' => 'Accès par le portail principal, badge remis par le chef de chantier.',
        'safety_equipment' => 'Casque, chaussures de sécurité, gilet haute visibilité, gants',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'priority' => SitePriority::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'submitted_on' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<Promoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }

    /**
     * @return HasMany<SiteDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SiteDocument::class);
    }

    /**
     * @return HasMany<SiteIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(SiteIncident::class)->latest('occurred_on')->latest('id');
    }

    /**
     * @return HasMany<SiteActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(SiteActivity::class)->latest('occurred_at')->latest('id');
    }

    /**
     * Date du jour des données de démonstration (config/admin.php).
     */
    public static function referenceDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('admin.sites_reference_date') ?? today());
    }

    /**
     * Ville d’une commune de la carte (les communes du district relèvent d’Abidjan).
     */
    public static function cityOf(string $commune): string
    {
        return in_array($commune, ['Yamoussoukro', 'Grand-Bassam'], true) ? $commune : 'Abidjan';
    }

    /**
     * Jours ouvrés (6 jours sur 7) entre deux dates.
     */
    public static function workingDaysBetween(Carbon|CarbonImmutable $start, Carbon|CarbonImmutable $end): int
    {
        return (int) round($start->diffInDays($end) * 6 / 7);
    }

    /**
     * Jours ouvrés écoulés depuis le démarrage, bornés à la durée prévue ; nul avant le démarrage.
     */
    public function executedDays(): int
    {
        if (in_array($this->status, [SiteStatus::Draft, SiteStatus::Planned, SiteStatus::Ready], true)) {
            return 0;
        }

        $until = self::referenceDate()->min($this->ends_on);
        $days = $until->lessThan($this->starts_on) ? 0 : self::workingDaysBetween($this->starts_on, $until);

        return max(0, min($this->planned_days, $days));
    }

    /**
     * Part du temps prévu déjà consommée (%).
     */
    public function elapsedPercent(): int
    {
        return (int) round($this->executedDays() / max(1, $this->planned_days) * 100);
    }

    /**
     * En retard : statut « En retard » ou avancement inférieur de plus de 15 points au temps consommé.
     */
    public function isLate(): bool
    {
        return $this->status === SiteStatus::Late
            || ($this->executedDays() > 0 && $this->progress < $this->elapsedPercent() - 15);
    }

    public function vacancies(): int
    {
        return max(0, $this->required_workers - $this->assigned_workers);
    }

    public function openIncidentsCount(): int
    {
        return $this->incidents->where('status', '!=', SiteIncidentStatus::Resolved)->count();
    }

    /**
     * Phases avec leur date prévue et leur état (réalisé, en cours, en retard ou prévu).
     *
     * @return array<int, array{label: string, threshold: int, date: string, status: string}>
     */
    public function milestones(): array
    {
        $length = $this->starts_on->diffInDays($this->ends_on);
        $today = self::referenceDate();

        return array_map(function (array $milestone, int $i) use ($length, $today): array {
            [$label, $threshold] = $milestone;
            $previous = $i ? self::MILESTONES[$i - 1][1] : 0;
            $date = $this->starts_on->copy()->addDays((int) round($length * $threshold / 100));
            $status = match (true) {
                $this->progress >= $threshold => 'Réalisé',
                $this->progress > $previous => 'En cours',
                $date->lessThan($today) => 'En retard',
                default => 'Prévu',
            };

            return ['label' => $label, 'threshold' => $threshold, 'date' => $date->format('Y-m-d'), 'status' => $status];
        }, self::MILESTONES, array_keys(self::MILESTONES));
    }

    /**
     * Crée les six pièces du dossier, toutes manquantes.
     */
    public function createMissingDocuments(): void
    {
        foreach (SiteDocumentType::cases() as $type) {
            $this->documents()->firstOrCreate(['type' => $type], ['status' => DocumentStatus::Missing]);
        }
    }

    /**
     * Ajoute un événement au journal du chantier.
     */
    public function logActivity(SiteActivityCategory $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, string $origin = 'Back-office', ?string $author = null): SiteActivity
    {
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
     * Change le statut et trace le changement dans le journal.
     *
     * @param  array<string, mixed>  $changes
     */
    public function transitionTo(SiteStatus $status, string $action, ?string $comment = null, array $changes = []): void
    {
        $old = $this->status;
        $this->update([...$changes, 'status' => $status]);
        $this->logActivity(SiteActivityCategory::Status, $action, $old->label(), $status->label(), $comment);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'chantier';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Prochaine référence « CH-2026-0xxx ».
     */
    public static function nextReference(): string
    {
        $last = static::query()->pluck('reference')->map(fn (string $r): int => (int) substr($r, -4))->max() ?? 100;

        return sprintf('CH-%s-%04d', self::referenceDate()->year, $last + 1);
    }
}
