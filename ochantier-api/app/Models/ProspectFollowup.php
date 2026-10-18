<?php

namespace App\Models;

use App\Enums\ProspectFollowupChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relance planifiée ou réalisée auprès d’un prospect.
 */
#[Fillable([
    'prospect_id', 'commercial_id', 'channel', 'subject', 'planned_at', 'done_at', 'result', 'client_uuid',
])]
class ProspectFollowup extends Model
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
            'channel' => ProspectFollowupChannel::class,
            'planned_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }
}
