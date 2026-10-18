<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
    'user_type', 'first_name', 'last_name', 'phone', 'phone_verified_at', 'email', 'email_verified_at',
    'password_hash', 'status', 'must_change_password', 'failed_login_count', 'locked_until', 'locale', 'preferences',
    'last_login_at', 'created_by_admin_id', 'suspended_at', 'suspended_by_admin_id', 'suspended_reason',
])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
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
}
