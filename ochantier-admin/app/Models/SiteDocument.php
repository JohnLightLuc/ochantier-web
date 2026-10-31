<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\SiteDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce du dossier d’un chantier (autorisations, pièces administratives, sécurité).
 */
class SiteDocument extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['type', 'reference', 'file_name', 'file_path', 'added_at', 'expires_at', 'status', 'rejection_reason', 'internal_comment'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SiteDocumentType::class,
            'status' => DocumentStatus::class,
            'added_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function hasFile(): bool
    {
        return $this->status !== DocumentStatus::Missing;
    }
}
