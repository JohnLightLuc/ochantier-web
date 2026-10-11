<?php

namespace App\Models;

use App\Enums\SiteActivityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Événement du journal d’un chantier.
 */
#[Fillable(['occurred_at', 'author', 'category', 'action', 'old_value', 'new_value', 'comment', 'origin'])]
class SiteActivity extends Model
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
            'category' => SiteActivityCategory::class,
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
