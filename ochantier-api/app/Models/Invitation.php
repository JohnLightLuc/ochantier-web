<?php

namespace App\Models;

use App\Enums\MessageChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Invitation à activer un compte, envoyée par le Back Office ou un promoteur.
 */
#[Fillable([
    'authenticatable_type', 'authenticatable_id', 'channel', 'token_hash', 'invited_by_admin_id',
    'invited_by_user_id', 'sent_at', 'expires_at', 'accepted_at', 'cancelled_at',
])]
#[Hidden(['token_hash'])]
#[WithoutTimestamps]
class Invitation extends Model
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
            'channel' => MessageChannel::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
