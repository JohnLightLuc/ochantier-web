<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Abonnement d’un promoteur. Sans abonnement payant (formule Solo), seul le propriétaire utilise le compte ;
 * une formule équipe permet d’inviter des collaborateurs. Une montée de formule est immédiate (prorata facturé),
 * une descente est programmée à la prochaine échéance.
 */
#[Fillable(['promoter_id', 'plan_key', 'previous_plan_key', 'status', 'since', 'next_due', 'payment_method', 'pending_plan_key', 'pending_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public const STATUSES = ['Actif' => 'success', 'Essai' => 'info', 'Impayé' => 'danger', 'Suspendu' => 'orange', 'Résilié' => 'neutral'];

    public const ROLES = ['Chef de chantier', 'Technicien', 'Comptable', 'Observateur'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'since' => 'date',
            'next_due' => 'date',
            'pending_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Promoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_key', 'key');
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'pending_plan_key', 'key');
    }

    /**
     * @return HasMany<SubscriptionUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(SubscriptionUser::class)->orderByDesc('owner')->orderBy('id');
    }

    /**
     * @return HasMany<SubscriptionInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->latest('date')->latest('id');
    }

    /**
     * @return HasMany<SubscriptionActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(SubscriptionActivity::class)->latest('date')->latest('id');
    }

    public static function referenceDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('admin.attendance_reference_date') ?? today());
    }

    /**
     * Abonnement vivant (payé, en essai ou en retard de paiement).
     */
    public function isLive(): bool
    {
        return in_array($this->status, ['Actif', 'Impayé', 'Essai'], true);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['Suspendu', 'Résilié'], true);
    }

    public function log(string $text): void
    {
        $this->activities()->create(['date' => self::referenceDate(), 'text' => $text]);
    }
}
