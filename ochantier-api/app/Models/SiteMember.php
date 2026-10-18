<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personnes autorisées sur un chantier (gestion, pointage, paiement).
 */
#[Fillable([
    'site_id', 'user_id', 'can_manage', 'can_check_in', 'can_pay', 'granted_by_user_id', 'granted_at', 'revoked_at',
])]
#[WithoutTimestamps]
class SiteMember extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_manage' => 'boolean',
            'can_check_in' => 'boolean',
            'can_pay' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
