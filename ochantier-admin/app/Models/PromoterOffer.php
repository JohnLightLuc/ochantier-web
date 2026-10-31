<?php

namespace App\Models;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedUrgency;
use App\Enums\PromoterOfferProfileStatus;
use App\Support\AssignmentInsights;
use Database\Factories\PromoterOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Offre envoyée par un promoteur depuis son application, avec les ouvriers qu’il a déjà choisis.
 *
 * Chaque profil est contacté puis validé (ou remplacé) ; une fois tous validés, le promoteur est notifié
 * automatiquement pour régler l’acompte, et le paiement de l’acompte comble l’offre.
 */
class PromoterOffer extends Model
{
    /** @use HasFactory<PromoterOfferFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['slug', 'reference', 'site_id', 'trade', 'specialty', 'urgency', 'sent_on', 'starts_on', 'ends_on', 'daily_cost', 'message', 'cancelled', 'notified_on', 'payment_reference'];

    public const TO_PROCESS = 'À traiter';

    public const REPLACEMENT = 'Remplacement à trouver';

    public const TO_NOTIFY = 'Promoteur à notifier';

    public const DEPOSIT = 'Acompte en attente';

    public const DONE = 'Comblé · chantier en cours';

    public const CANCELLED = 'Annulée';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urgency' => NeedUrgency::class,
            'sent_on' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'notified_on' => 'date',
            'cancelled' => 'boolean',
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
     * @return HasMany<PromoterOfferProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(PromoterOfferProfile::class)->orderBy('id');
    }

    /**
     * @return HasMany<PromoterOfferActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(PromoterOfferActivity::class)->latest('occurred_at')->latest('id');
    }

    /**
     * Étape de traitement : profils à traiter, refus à remplacer, promoteur à notifier, acompte, puis comblée.
     */
    public function status(): string
    {
        $statuses = $this->profiles->pluck('status');

        return match (true) {
            $this->cancelled => self::CANCELLED,
            $statuses->contains(fn (PromoterOfferProfileStatus $s): bool => in_array($s, [PromoterOfferProfileStatus::Pending, PromoterOfferProfileStatus::Contacted], true)) => self::TO_PROCESS,
            $statuses->contains(fn (PromoterOfferProfileStatus $s): bool => $s->isRefused()) => self::REPLACEMENT,
            ! $this->notified_on => self::TO_NOTIFY,
            ! $this->payment_reference => self::DEPOSIT,
            default => self::DONE,
        };
    }

    /**
     * Profils par étape (les profils remplacés ne comptent plus dans le total).
     *
     * @return array{accepted: int, pending: int, contacted: int, refused: int, replaced: int, total: int}
     */
    public function counts(): array
    {
        $count = fn (PromoterOfferProfileStatus $s): int => $this->profiles->where('status', $s)->count();

        return [
            'accepted' => $count(PromoterOfferProfileStatus::Accepted),
            'pending' => $count(PromoterOfferProfileStatus::Pending),
            'contacted' => $count(PromoterOfferProfileStatus::Contacted),
            'refused' => $count(PromoterOfferProfileStatus::Refused) + $count(PromoterOfferProfileStatus::RefusedByWorker),
            'replaced' => $count(PromoterOfferProfileStatus::Replaced),
            'total' => $this->profiles->where('status', '!=', PromoterOfferProfileStatus::Replaced)->count(),
        ];
    }

    /**
     * Durée en jours calendaires, bornes incluses.
     */
    public function duration(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * Coût global des ouvriers validés sur les jours ouvrés, et acompte (taux X des Paramètres).
     *
     * @return array{rate: int, accepted: int, days: int, total: int, deposit: int}
     */
    public function deposit(): array
    {
        $accepted = $this->profiles->where('status', PromoterOfferProfileStatus::Accepted);
        $days = AssignmentInsights::workingDays($this->duration());
        $total = (int) $accepted->sum(fn (PromoterOfferProfile $p): int => ($p->daily_cost ?: $this->daily_cost) * $days);

        return ['rate' => AssignmentInsights::depositRate(), 'accepted' => $accepted->count(), 'days' => $days, 'total' => $total, 'deposit' => AssignmentInsights::depositOf($total)];
    }

    public function isOpen(): bool
    {
        return ! $this->cancelled;
    }

    /**
     * Ajoute un événement au journal de l’offre.
     */
    public function logActivity(NeedActivityCategory $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, string $origin = 'Back-office', ?string $author = null): PromoterOfferActivity
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
}
