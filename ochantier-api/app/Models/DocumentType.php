<?php

namespace App\Models;

use App\Casts\PostgresArray;
use App\Enums\DocumentAudience;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Types de pièces : configure les documents requis par profil ou métier.
 */
#[Fillable([
    'code', 'label', 'audience', 'trade_id', 'is_required', 'is_identity', 'has_back_side', 'requires_expiry',
    'validity_months', 'allowed_mime', 'max_size_kb', 'is_sensitive', 'is_active',
])]
class DocumentType extends Model
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
            'audience' => DocumentAudience::class,
            'is_required' => 'boolean',
            'is_identity' => 'boolean',
            'has_back_side' => 'boolean',
            'requires_expiry' => 'boolean',
            'allowed_mime' => PostgresArray::class,
            'is_sensitive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
