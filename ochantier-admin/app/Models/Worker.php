<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\WorkerActivityCategory;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerStatus;
use Database\Factories\WorkerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ouvrier inscrit sur la plateforme.
 *
 * `demo_relations` contient les affectations, pointages, paiements, avis, remplacements et
 * statistiques de démonstration ; ils migreront vers les modules Affectations, Pointages, Paiements et Avis.
 */
class Worker extends Model
{
    /** @use HasFactory<WorkerFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug', 'code', 'photo_path', 'first_name', 'last_name', 'gender', 'birth_date', 'nationality',
        'phone', 'phone_secondary', 'email', 'address', 'commune', 'city', 'country', 'emergency_contact', 'emergency_phone',
        'trade', 'secondary_trades', 'specialties', 'skills', 'certifications', 'qualification', 'experience_years',
        'experience_description', 'daily_rate', 'mobility', 'zones', 'works_solo', 'works_in_team', 'accepts_contracts',
        'id_type', 'id_number', 'id_issued_at', 'id_expires_at', 'recruiter', 'sales_zone', 'acquisition_channel', 'sales_note',
        'status', 'availability', 'previous_availability', 'suspension_reason', 'rejection', 'identity_mismatch',
        'possible_duplicate', 'rating', 'reviews_count', 'registered_at', 'demo_relations',
    ];

    /**
     * Statuts d’affectation encore actifs (ni terminée, ni annulée, ni refusée, ni remplacée).
     */
    public const ACTIVE_ASSIGNMENT_STATUSES = ['Proposée', 'En attente d’acceptation', 'Acceptée', 'À valider', 'Confirmée', 'En cours', 'Suspendue', 'Remplacement demandé'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'id_issued_at' => 'date',
            'id_expires_at' => 'date',
            'registered_at' => 'date',
            'secondary_trades' => 'array',
            'specialties' => 'array',
            'skills' => 'array',
            'certifications' => 'array',
            'zones' => 'array',
            'rejection' => 'array',
            'demo_relations' => 'array',
            'works_solo' => 'boolean',
            'works_in_team' => 'boolean',
            'accepts_contracts' => 'boolean',
            'identity_mismatch' => 'boolean',
            'possible_duplicate' => 'boolean',
            'rating' => 'float',
            'status' => WorkerStatus::class,
            'availability' => WorkerAvailability::class,
            'previous_availability' => WorkerAvailability::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->first_name} {$this->last_name}");
    }

    /**
     * @return HasMany<WorkerDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(WorkerDocument::class);
    }

    /**
     * @return HasMany<WorkerExperience, $this>
     */
    public function experiences(): HasMany
    {
        return $this->hasMany(WorkerExperience::class)->latest('started_at');
    }

    /**
     * Périodes déclarées dans le calendrier.
     *
     * @return HasMany<WorkerPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(WorkerPeriod::class)->orderBy('starts_on');
    }

    /**
     * @return HasMany<WorkerActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkerActivity::class)->latest('occurred_at')->latest('id');
    }

    public function isArchived(): bool
    {
        return $this->status === WorkerStatus::Archived;
    }

    /**
     * Données de démonstration d’une relation (assignments, timesheets, payouts, reviews, replacements, stats).
     *
     * @return array<int|string, mixed>
     */
    public function demo(string $relation): array
    {
        return $this->demo_relations[$relation] ?? [];
    }

    /**
     * Remplace une liste de démonstration.
     *
     * @param  array<int|string, mixed>  $items
     */
    public function putDemo(string $relation, array $items): void
    {
        $this->update(['demo_relations' => [...($this->demo_relations ?? []), $relation => $items]]);
    }

    /**
     * Applique des modifications à un élément de démonstration identifié par son `id`.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed> L’élément modifié.
     */
    public function updateDemo(string $relation, string $id, array $changes): array
    {
        $updated = null;
        $items = array_map(function (array $item) use ($id, $changes, &$updated): array {
            if ($item['id'] !== $id) {
                return $item;
            }

            return $updated = [...$item, ...$changes];
        }, $this->demo($relation));

        abort_unless($updated, 404);
        $this->putDemo($relation, $items);

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function findDemo(string $relation, string $id): array
    {
        $item = collect($this->demo($relation))->firstWhere('id', $id);
        abort_unless($item, 404);

        return $item;
    }

    /**
     * Ajoute un événement au journal de l’ouvrier.
     */
    public function logActivity(WorkerActivityCategory $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, string $origin = 'Back-office', ?string $author = null): WorkerActivity
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
     * Signaux du bandeau « À traiter » : profil à vérifier, remplacement ouvert, pièce expirée ou manquante.
     *
     * @return array{review: bool, replacement: bool, documents: bool}
     */
    public function flags(): array
    {
        $openCase = collect($this->demo('replacements'))->contains(fn (array $c): bool => ! in_array($c['status'], ['Clôturé', 'Annulé', 'Refusé'], true));
        $busy = collect($this->demo('assignments'))->contains(fn (array $a): bool => in_array($a['status'], ['En cours', 'Confirmée', 'Remplacement demandé'], true));

        return [
            'review' => $this->status->isUnderReview(),
            'replacement' => $openCase || ($this->availability === WorkerAvailability::Unavailable && $busy),
            'documents' => $this->documents->contains(fn (WorkerDocument $d): bool => in_array($d->status, [DocumentStatus::Expired, DocumentStatus::Missing], true)),
        ];
    }

    /**
     * Identifiant d’URL unique dérivé du nom.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'ouvrier';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Prochain matricule « OUV-0nnn ».
     */
    public static function nextCode(): string
    {
        $last = static::query()->pluck('code')->map(fn (string $c): int => (int) substr($c, 4))->max() ?? 0;

        return sprintf('OUV-%04d', $last + 1);
    }

    /**
     * Les 10 chiffres d’un numéro, sans l’indicatif +225.
     */
    public static function phoneDigits(?string $phone): string
    {
        return substr(preg_replace('/\D/', '', preg_replace('/^\s*\+?\s*225\s*/', '', (string) $phone)), 0, 10);
    }

    /**
     * Recherche par nom, matricule, téléphone, e-mail ou métier.
     *
     * @param  Builder<Worker>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $digits = preg_replace('/\D/', '', $term);

        $query->where(function (Builder $q) use ($term, $digits): void {
            foreach (['first_name', 'last_name', 'code', 'email', 'trade', 'phone'] as $column) {
                $q->orWhere($column, 'like', "%{$term}%");
            }
            $q->orWhereRaw("(first_name || ' ' || last_name) like ?", ["%{$term}%"]);
            if (strlen($digits) >= 4) {
                $q->orWhereRaw("replace(phone, ' ', '') like ?", ["%{$digits}%"]);
            }
        });
    }
}
