<?php

namespace App\Models;

use App\Enums\RetentionAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durée de conservation d’une catégorie de données.
 */
#[Fillable([
    'data_category', 'retention_days', 'action', 'updated_by_admin_id',
])]
class DataRetentionPolicy extends Model
{
    use HasUuids;

    public const CREATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => RetentionAction::class,
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
