<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\SalesAgentActivityCategory;
use App\Enums\SalesAgentAvailability;
use App\Enums\SalesAgentStatus;
use Database\Factories\SalesAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Commercial terrain : recrute des ouvriers et se connecte à l’Application Commercial.
 */
#[Fillable([
    'slug', 'code', 'photo_path', 'first_name', 'last_name', 'gender', 'birth_date', 'nationality', 'phone', 'phone_secondary',
    'email', 'address', 'commune', 'city', 'emergency_contact', 'emergency_phone', 'type', 'job_title', 'agency', 'zone',
    'secondary_zones', 'communes', 'priority_trades', 'specialties', 'supervisor', 'started_at', 'contract', 'weekly_target',
    'monthly_target', 'internal_note', 'id_type', 'id_number', 'id_issued_at', 'id_expires_at', 'status', 'invitation_status',
    'availability', 'previous_availability', 'suspension_reason', 'last_activity_on', 'recruited_total', 'ranking_until', 'pay', 'access',
    'score_adjustments', 'payout_statuses',
])]
class SalesAgent extends Model
{
    /** @use HasFactory<SalesAgentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'started_at' => 'date',
            'id_issued_at' => 'date',
            'id_expires_at' => 'date',
            'last_activity_on' => 'date',
            'secondary_zones' => 'array',
            'communes' => 'array',
            'priority_trades' => 'array',
            'specialties' => 'array',
            'pay' => 'array',
            'access' => 'array',
            'score_adjustments' => 'array',
            'payout_statuses' => 'array',
            'status' => SalesAgentStatus::class,
            'invitation_status' => InvitationStatus::class,
            'availability' => SalesAgentAvailability::class,
            'previous_availability' => SalesAgentAvailability::class,
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
     * Agence de rattachement, ou mention « indépendant ».
     */
    public function agencyLabel(): string
    {
        return $this->agency ?: 'Indépendant · sans agence';
    }

    public function isArchived(): bool
    {
        return $this->status === SalesAgentStatus::Archived;
    }

    /**
     * @return HasMany<SalesAgentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SalesAgentDocument::class);
    }

    /**
     * @return HasMany<SalesAgentPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SalesAgentPeriod::class)->orderBy('starts_on');
    }

    /**
     * @return HasMany<SalesAgentObjective, $this>
     */
    public function objectives(): HasMany
    {
        return $this->hasMany(SalesAgentObjective::class)->orderByDesc('starts_on');
    }

    /**
     * Bonus (montant positif) et retenues (montant négatif).
     *
     * @return HasMany<SalesAgentAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(SalesAgentAdjustment::class)->latest('granted_on')->latest('id');
    }

    /**
     * @return HasMany<SalesAgentReward, $this>
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(SalesAgentReward::class)->orderByDesc('month');
    }

    /**
     * @return HasMany<SalesAgentAppreciation, $this>
     */
    public function appreciations(): HasMany
    {
        return $this->hasMany(SalesAgentAppreciation::class)->latest();
    }

    /**
     * @return HasMany<SalesAgentFieldActivity, $this>
     */
    public function fieldActivities(): HasMany
    {
        return $this->hasMany(SalesAgentFieldActivity::class)->latest('occurred_at');
    }

    /**
     * @return HasMany<SalesAgentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(SalesAgentActivity::class)->latest('occurred_at')->latest('id');
    }

    /**
     * Ouvriers recrutés par ce commercial, rattachés par nom en attendant une clé étrangère sur `workers`.
     *
     * @return Builder<Worker>
     */
    public function recruitedWorkers(): Builder
    {
        return Worker::query()->where('recruiter', $this->name);
    }

    /**
     * Met à jour une partie des accès (rôle, permissions, sessions…).
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateAccess(array $changes): void
    {
        $this->update(['access' => [...$this->access, ...$changes]]);
    }

    /**
     * Ajoute un événement au journal du commercial.
     */
    public function logActivity(SalesAgentActivityCategory $category, string $action, ?string $old = null, ?string $new = null, ?string $comment = null, string $origin = 'Back-office', ?string $author = null): SalesAgentActivity
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
            'ip' => $origin === 'Back-office' ? request()->ip() : null,
        ]);
    }

    /**
     * Rémunération par défaut selon le type de commercial et le contrat.
     *
     * @return array{mode: string, fixed: int, per_registered: int, per_validated: int, bonus_objective: int, bonus_cotm: int, calc_frequency: string, pay_frequency: string, effective_from: string, cap: int, eligibility: string, method: string}
     */
    public static function defaultPay(string $type, ?string $contract, string $effectiveFrom, string $method = 'Orange Money'): array
    {
        $commissionOnly = $type === 'Commercial indépendant' || $contract === 'Convention';

        return [
            'mode' => $commissionOnly ? 'Commission' : 'Fixe et commission',
            'fixed' => $contract === 'Convention' ? 0 : 150000,
            'per_registered' => 1500,
            'per_validated' => 3500,
            'bonus_objective' => 50000,
            'bonus_cotm' => 100000,
            'calc_frequency' => 'Hebdomadaire',
            'pay_frequency' => 'Mensuelle',
            'effective_from' => $effectiveFrom,
            'cap' => 600000,
            'eligibility' => 'Profils validés uniquement, hors doublons et rejets',
            'method' => $method,
        ];
    }

    /**
     * Accès par défaut à l’Application Commercial selon le type de commercial.
     *
     * @return array<string, mixed>
     */
    public static function defaultAccess(string $type, string $zone): array
    {
        $supervisor = in_array($type, ['Superviseur commercial', 'Responsable de zone'], true);
        $field = $type === 'Agent terrain';

        return [
            'role' => $supervisor ? 'Superviseur' : 'Commercial terrain',
            'level' => $field ? 'Standard' : 'Étendu',
            'modules' => ['Ouvriers', 'Documents', 'Gains'],
            'data_zones' => [$zone],
            'permissions' => ['create' => true, 'edit_own' => true, 'documents' => true, 'submit' => true, 'assignments' => ! $field, 'earnings' => true, 'export' => ! $field],
            'two_factor' => true,
            'duration' => 'Illimitée',
            'sent_at' => null,
            'viewed_at' => null,
            'activated_at' => null,
            'expires_at' => null,
            'last_login' => null,
            'password_changed_at' => null,
            'failed_attempts' => 0,
            'sessions' => [],
        ];
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'commercial';
        $slug = $base;

        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Prochain identifiant « COM-0nn ».
     */
    public static function nextCode(): string
    {
        $last = static::query()->pluck('code')->map(fn (string $c): int => (int) substr($c, 4))->max() ?? 0;

        return sprintf('COM-%03d', $last + 1);
    }
}
