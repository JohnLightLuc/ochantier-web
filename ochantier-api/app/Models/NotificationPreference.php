<?php

namespace App\Models;

use App\Enums\PreferenceChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Préférence de notification d’un utilisateur par catégorie et canal.
 */
#[Fillable([
    'user_id', 'category', 'channel', 'enabled',
])]
#[WithoutTimestamps]
#[WithoutIncrementing]
class NotificationPreference extends Model
{
    /**
     * Clé primaire composite : le modèle n’a pas de clé simple.
     *
     * @var string|null
     */
    protected $primaryKey = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => PreferenceChannel::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
