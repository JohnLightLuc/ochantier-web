<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Code à usage unique envoyé par SMS ou e-mail (activation, connexion, action sensible).
 */
#[Fillable([
    'authenticatable_type', 'authenticatable_id', 'channel', 'destination', 'purpose', 'code_hash', 'attempts',
    'max_attempts', 'expires_at', 'consumed_at',
])]
#[Hidden(['code_hash'])]
class OtpCode extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
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
