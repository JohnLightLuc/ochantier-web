<?php

namespace App\Models;

use App\Enums\SalesAgentActivityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Événement du journal d’un commercial.
 */
#[Fillable(['occurred_at', 'author', 'category', 'action', 'old_value', 'new_value', 'comment', 'origin', 'ip'])]
class SalesAgentActivity extends Model
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
            'category' => SalesAgentActivityCategory::class,
        ];
    }

    /**
     * @return BelongsTo<SalesAgent, $this>
     */
    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class);
    }
}
