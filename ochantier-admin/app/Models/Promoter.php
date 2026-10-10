<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\ActivityAuthorKind;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PromoterPaymentStatus;
use App\Enums\PromoterStatus;
use Database\Factories\PromoterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Compte promoteur (entreprise, institution ou particulier qui publie des chantiers).
 *
 * `demo_relations` contient les chantiers, offres, paiements, avis et litiges de démonstration
 * repris de la maquette ; ces données migreront vers leurs propres tables avec les modules concernés.
 */
#[Fillable([
    'slug', 'name', 'legal_form', 'rccm', 'ncc', 'sector', 'head_office',
    'representative', 'representative_role', 'phone', 'email',
    'account_type', 'location', 'status', 'payment_status', 'note',
    'suspension_reason', 'suspended_at', 'registered_at', 'last_active_at', 'demo_relations',
])]
class Promoter extends Model
{
    /** @use HasFactory<PromoterFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'status' => PromoterStatus::class,
            'payment_status' => PromoterPaymentStatus::class,
            'suspended_at' => 'date',
            'registered_at' => 'date',
            'last_active_at' => 'datetime',
            'demo_relations' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<PromoterDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PromoterDocument::class);
    }

    /**
     * @return HasMany<PromoterActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(PromoterActivity::class)->latest('occurred_at')->latest('id');
    }

    /**
     * Crée les cinq pièces du dossier KYC, toutes manquantes.
     */
    public function createMissingDocuments(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->documents()->firstOrCreate(['type' => $type], ['status' => DocumentStatus::Missing]);
        }
    }

    /**
     * État KYC global : le statut le plus grave parmi les pièces obligatoires.
     */
    public function kycStatus(): DocumentStatus
    {
        $statuses = $this->documents
            ->filter(fn (PromoterDocument $document): bool => $document->type->isRequired())
            ->pluck('status');

        foreach (DocumentStatus::SEVERITY as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return DocumentStatus::Verified;
    }

    /**
     * Données de démonstration d’une relation (chantiers, offers, payments, reviews, disputes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function demo(string $relation): array
    {
        return $this->demo_relations[$relation] ?? [];
    }

    /**
     * Remplace un élément de démonstration identifié par sa clé.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateDemo(string $relation, string $key, string $id, array $changes): void
    {
        $relations = $this->demo_relations ?? [];
        $relations[$relation] = array_map(
            fn (array $item): array => $item[$key] === $id ? [...$item, ...$changes] : $item,
            $relations[$relation] ?? [],
        );
        $this->update(['demo_relations' => $relations]);
    }

    public function hasOpenDisputes(): bool
    {
        return collect($this->demo('disputes'))->contains(fn (array $dispute): bool => $dispute['status'] !== 'Résolu');
    }

    /**
     * Ajoute un événement au journal d’activité et met à jour la dernière activité.
     */
    public function logActivity(string $action, ?string $target, string $icon, string $tone, ?string $link = null, ?string $author = null, ActivityAuthorKind $kind = ActivityAuthorKind::Admin): PromoterActivity
    {
        $this->forceFill(['last_active_at' => now()])->save();

        return $this->activities()->create([
            'occurred_at' => now(),
            'author' => $author ?? auth()->user()?->name ?? 'Système',
            'author_kind' => $kind,
            'action' => $action,
            'target' => $target,
            'icon' => $icon,
            'tone' => $tone,
            'link' => $link,
        ]);
    }

    /**
     * Identifiant d’URL unique dérivé de la raison sociale.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'promoteur';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Recherche par représentant, raison sociale, téléphone ou e-mail.
     *
     * @param  Builder<Promoter>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term): void {
            foreach (['representative', 'name', 'phone', 'email'] as $column) {
                $q->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    /**
     * Promoteurs dont l’état KYC global vaut le statut donné.
     *
     * @param  Builder<Promoter>  $query
     */
    public function scopeWithKycStatus(Builder $query, DocumentStatus $status): void
    {
        $required = array_map(fn (DocumentType $t): string => $t->value, array_filter(DocumentType::cases(), fn (DocumentType $t): bool => $t->isRequired()));
        $moreSevere = $status === DocumentStatus::Verified
            ? DocumentStatus::SEVERITY
            : array_slice(DocumentStatus::SEVERITY, 0, array_search($status, DocumentStatus::SEVERITY, true));

        if ($status !== DocumentStatus::Verified) {
            $query->whereHas('documents', fn (Builder $q) => $q->whereIn('type', $required)->where('status', $status));
        }

        if ($moreSevere !== []) {
            $query->whereDoesntHave('documents', fn (Builder $q) => $q->whereIn('type', $required)->whereIn('status', $moreSevere));
        }

    }

    /**
     * Au moins une pièce KYC obligatoire manquante.
     *
     * @param  Builder<Promoter>  $query
     */
    public function scopeWithIncompleteFile(Builder $query): void
    {
        $query->whereHas('documents', fn (Builder $q) => $q
            ->where('status', DocumentStatus::Missing)
            ->where('type', '!=', DocumentType::Additional));
    }

    /**
     * Au moins une pièce KYC expirée.
     *
     * @param  Builder<Promoter>  $query
     */
    public function scopeWithExpiredDocuments(Builder $query): void
    {
        $query->whereHas('documents', fn (Builder $q) => $q->where('status', DocumentStatus::Expired));
    }
}
