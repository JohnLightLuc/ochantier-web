<?php

namespace App\Models;

use App\Enums\ReplacementStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des statuts d’un remplacement.
 */
class ReplacementStatusHistory extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'replacement_id', 'from_status', 'to_status', 'reason', 'actor_type', 'actor_id',
    ];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ReplacementStatus::class,
            'to_status' => ReplacementStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Replacement, $this>
     */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Replacement::class);
    }
}
