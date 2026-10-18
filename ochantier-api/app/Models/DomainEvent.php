<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Boîte d'envoi des événements métier (notifications, agrégats, intégrations).
 */
#[Fillable([
    'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'correlation_id', 'occurred_at', 'published_at',
    'attempts', 'last_error',
])]
#[WithoutTimestamps]
class DomainEvent extends Model
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
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function aggregate(): MorphTo
    {
        return $this->morphTo();
    }
}
