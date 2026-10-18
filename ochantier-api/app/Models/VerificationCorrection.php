<?php

namespace App\Models;

use App\Enums\VerificationCorrectionPriority;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Correction demandée lors d’un contrôle de dossier.
 */
#[Fillable([
    'review_id', 'target_field', 'document_id', 'reason', 'priority', 'due_at', 'resolved_at', 'resolution_comment',
])]
class VerificationCorrection extends Model
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
            'priority' => VerificationCorrectionPriority::class,
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VerificationReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(VerificationReview::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
