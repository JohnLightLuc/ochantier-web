<?php

namespace App\Models;

use App\Enums\NeedActivityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Événement du journal d’un besoin.
 */
#[Fillable(['occurred_at', 'author', 'category', 'action', 'old_value', 'new_value', 'comment', 'origin'])]
class NeedActivity extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'category' => NeedActivityCategory::class,
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
