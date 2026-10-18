<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paramètres applicatifs (délais, seuils de verrouillage, arrondis, sessions…).
 */
#[Fillable([
    'key', 'value', 'description', 'updated_by_admin_id',
])]
#[Table(key: 'key', keyType: 'string', incrementing: false)]
class Setting extends Model
{
    public const CREATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<SettingHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(SettingHistory::class, 'setting_key');
    }
}
