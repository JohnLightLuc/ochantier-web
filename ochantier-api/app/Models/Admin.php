<?php

namespace App\Models;

use App\Enums\AdminStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Administrateurs du Back Office (séparés des utilisateurs).
 */
#[Fillable([
    'first_name', 'last_name', 'email', 'phone', 'department', 'password_hash', 'status', 'mfa_enabled',
    'mfa_secret_encrypted', 'must_change_password', 'failed_login_count', 'locked_until', 'access_expires_at',
    'last_login_at', 'last_login_ip', 'preferences', 'invited_by_admin_id', 'suspended_at', 'suspended_reason',
])]
#[Hidden(['password_hash', 'mfa_secret_encrypted'])]
class Admin extends Authenticatable
{
    use HasUuids, SoftDeletes;

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
}
