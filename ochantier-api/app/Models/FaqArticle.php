<?php

namespace App\Models;

use App\Enums\Audience;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article d’aide (FAQ, aide contextuelle d’un écran).
 */
#[Fillable([
    'section', 'audience', 'title', 'content', 'context_key', 'version', 'is_published', 'published_at',
    'updated_by_admin_id',
])]
class FaqArticle extends Model
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
            'audience' => Audience::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
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
