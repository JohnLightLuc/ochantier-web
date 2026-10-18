<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tout fichier stocké (stockage objet S3) : photos, signatures, pièces, reçus.
 */
#[Fillable([
    'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'width', 'height', 'purpose',
    'is_sensitive', 'captured_at', 'uploaded_by_type', 'uploaded_by_id', 'client_uuid', 'metadata',
])]
class MediaFile extends Model
{
    use HasUuids, SoftDeletes;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'captured_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function uploadedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
