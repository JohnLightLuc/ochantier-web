<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des modifications d’un paramètre applicatif.
 */
#[Fillable([
    'setting_key', 'old_value', 'new_value', 'reason', 'changed_by_admin_id',
])]
class SettingHistory extends Model
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
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Setting, $this>
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_key', 'key');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function changedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
