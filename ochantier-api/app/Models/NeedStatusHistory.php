<?php

namespace App\Models;

use App\Enums\NeedStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des statuts d’un besoin.
 */
#[Fillable([
    'need_id', 'from_status', 'to_status', 'reason', 'actor_type', 'actor_id',
])]
class NeedStatusHistory extends Model
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
            'from_status' => NeedStatus::class,
            'to_status' => NeedStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }
}
