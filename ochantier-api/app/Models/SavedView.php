<?php

namespace App\Models;

use App\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vue filtrée enregistrée par un administrateur.
 */
#[Fillable([
    'admin_id', 'module', 'name', 'filters', 'is_shared', 'shared_with',
])]
class SavedView extends Model
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
            'filters' => 'array',
            'is_shared' => 'boolean',
            'shared_with' => PostgresArray::class,
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
