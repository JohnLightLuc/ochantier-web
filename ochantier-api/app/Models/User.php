<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Utilisateurs des applications (promoteurs, commerciaux, ouvriers en V2).
 */
#[Fillable([
    'user_type', 'first_name', 'last_name', 'dial_code', 'phone_number', 'phone_verified_at', 'email', 'email_verified_at',
    'password_hash', 'status', 'must_change_password', 'failed_login_count', 'locked_until', 'locale', 'preferences',
    'last_login_at', 'created_by_admin_id', 'suspended_at', 'suspended_by_admin_id', 'suspended_reason',
])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Colonne du mot de passe haché.
     *
     * @var string
     */
    protected $authPasswordName = 'password_hash';

    /**
     * Pas de jeton « se souvenir de moi » : l'authentification passe par JWT.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'preferences' => 'array',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function suspendedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasOne<PromoterProfile, $this>
     */
    public function promoterProfile(): HasOne
    {
        return $this->hasOne(PromoterProfile::class);
    }

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function organizationMembers(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return HasOne<CommercialProfile, $this>
     */
    public function commercialProfile(): HasOne
    {
        return $this->hasOne(CommercialProfile::class);
    }

    /**
     * @return HasOne<Worker, $this>
     */
    public function worker(): HasOne
    {
        return $this->hasOne(Worker::class);
    }

    /**
     * @return HasMany<SiteMember, $this>
     */
    public function siteMembers(): HasMany
    {
        return $this->hasMany(SiteMember::class);
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * @return MorphMany<Consent, $this>
     */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'subject');
    }

    /**
     * @return MorphMany<AuthSession, $this>
     */
    public function authSessions(): MorphMany
    {
        return $this->morphMany(AuthSession::class, 'authenticatable');
    }

    /**
     * @return MorphMany<DeviceToken, $this>
     */
    public function deviceTokens(): MorphMany
    {
        return $this->morphMany(DeviceToken::class, 'owner');
    }

    /**
     * Organisations dont l’utilisateur est propriétaire (promoteur entreprise).
     *
     * @return HasMany<Organization, $this>
     */
    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_user_id');
    }

    /**
     * Compte d’un type donné, retrouvé par son numéro au format E.164 (« +2250708091011 ») ou son e-mail.
     */
    public static function findByContact(UserType $type, ?string $phone, ?string $email = null): ?self
    {
        if (! $phone && ! $email) {
            return null;
        }

        return self::query()
            ->where('user_type', $type)
            ->when($phone, fn (Builder $query) => $query->where('phone', $phone))
            ->when(! $phone && $email, fn (Builder $query) => $query->where('email', $email))
            ->first();
    }

    /**
     * Seul un compte actif peut ouvrir ou conserver une session.
     */
    public function canSignIn(): bool
    {
        return $this->status === UserStatus::Actif && ! $this->trashed();
    }

    /**
     * Motif du refus de connexion d’un compte existant, ou null s’il peut se connecter.
     */
    public function signInRefusal(): ?string
    {
        return match ($this->status) {
            UserStatus::Suspendu => 'Votre compte est suspendu. Contactez le support O’CHANTIER.',
            UserStatus::Desactive => 'Votre compte est désactivé. Contactez le support O’CHANTIER.',
            default => null,
        };
    }

    public function isLocked(): bool
    {
        return $this->locked_until?->isFuture() ?? false;
    }

    /**
     * Compte un mot de passe erroné et verrouille le compte au-delà du nombre d’essais autorisés.
     */
    public function registerFailedLogin(): void
    {
        $attempts = $this->failed_login_count + 1;

        if ($attempts >= (int) config('auth.lockout.max_attempts')) {
            $this->forceFill(['failed_login_count' => 0, 'locked_until' => now()->addMinutes((int) config('auth.lockout.lockout_minutes'))])->save();

            return;
        }

        $this->forceFill(['failed_login_count' => $attempts])->save();
    }

    public function clearFailedLogins(): void
    {
        if ($this->failed_login_count > 0 || $this->locked_until !== null) {
            $this->forceFill(['failed_login_count' => 0, 'locked_until' => null])->save();
        }
    }
}
