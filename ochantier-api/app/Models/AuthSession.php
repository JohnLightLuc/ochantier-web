<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Sessions, tentatives, OTP, invitations (admins et utilisateurs).
 */
class AuthSession extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'authenticatable_type', 'authenticatable_id', 'access_token_hash', 'refresh_token_hash', 'abilities', 'device_id',
        'device_name', 'platform', 'app_name', 'app_version', 'ip_address', 'user_agent', 'mfa_verified_at',
        'last_activity_at', 'expires_at', 'revoked_at', 'revoked_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['access_token_hash', 'refresh_token_hash'];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'mfa_verified_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
