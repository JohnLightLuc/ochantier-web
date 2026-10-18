<?php

namespace App\Models;

use App\Enums\ConsentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Consentements horodatés et versionnés (utilisateurs ET ouvriers sans compte).
 */
#[Fillable([
    'subject_type', 'subject_id', 'consent_type', 'legal_document_id', 'granted', 'granted_at', 'revoked_at',
    'recorded_by_type', 'recorded_by_id', 'channel', 'proof_media_id', 'signature_media_id', 'context_type',
    'context_id', 'ip_address', 'device_id',
])]
class Consent extends Model
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
            'consent_type' => ConsentType::class,
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function proofMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function signatureMedia(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function recordedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function context(): MorphTo
    {
        return $this->morphTo();
    }
}
