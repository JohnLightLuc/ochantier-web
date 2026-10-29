<?php

namespace App\Models;

use App\Enums\AdminStatus;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Administrateurs du Back Office (séparés des utilisateurs).
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Codes des permissions accordées par les rôles, chargés une fois par instance.
     *
     * @var list<string>|null
     */
    private ?array $permissionCodes = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'department', 'password_hash', 'status', 'mfa_enabled',
        'mfa_secret_encrypted', 'must_change_password', 'failed_login_count', 'locked_until', 'access_expires_at',
        'last_login_at', 'last_login_ip', 'preferences', 'invited_by_admin_id', 'suspended_at', 'suspended_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['password_hash', 'mfa_secret_encrypted'];

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
            'password_hash' => 'hashed',
            'status' => AdminStatus::class,
            'mfa_enabled' => 'boolean',
            'mfa_secret_encrypted' => 'encrypted',
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'access_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function invitedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<DataExport, $this>
     */
    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    /**
     * @return HasMany<SavedView, $this>
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
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
     * @return BelongsToMany<AdminRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_assignments', 'admin_id', 'role_id')
            ->withPivot('granted_by_admin_id', 'granted_at');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function canSignIn(): bool
    {
        return $this->signInRefusal() === null && $this->password_hash !== null;
    }

    /**
     * Motif du refus de connexion d’un compte existant, ou null s’il peut se connecter.
     */
    public function signInRefusal(): ?string
    {
        if ($this->trashed()) {
            return 'Votre compte est désactivé. Contactez un super administrateur.';
        }

        $refusal = match ($this->status) {
            AdminStatus::Invite => 'Activez d’abord votre compte avec le lien reçu par e-mail.',
            AdminStatus::Suspendu => 'Votre compte est suspendu. Contactez un super administrateur.',
            AdminStatus::Desactive => 'Votre compte est désactivé. Contactez un super administrateur.',
            default => null,
        };

        return $refusal ?? ($this->access_expires_at?->isPast() ? 'Votre accès temporaire a expiré. Contactez un super administrateur.' : null);
    }

    /**
     * Code à usage unique exigé à la connexion : toujours si la politique l’impose, sinon selon le compte.
     */
    public function requiresMfa(): bool
    {
        return config('auth.admin.mfa_required') || $this->mfa_enabled;
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

    /**
     * Codes des permissions accordées par l’ensemble des rôles (ex. workers.validate).
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->permissionCodes ??= AdminPermission::query()
            ->whereHas('roles.admins', fn ($query) => $query->whereKey($this->getKey()))
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }
}
