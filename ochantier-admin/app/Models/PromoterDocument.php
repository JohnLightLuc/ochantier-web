<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Database\Factories\PromoterDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce du dossier KYC d’un promoteur.
 */
#[Fillable(['type', 'reference', 'added_at', 'expires_at', 'status', 'rejection_reason', 'file_path'])]
class PromoterDocument extends Model
{
    /** @use HasFactory<PromoterDocumentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'added_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Promoter, $this>
     */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(Promoter::class);
    }

    public function canBeValidated(): bool
    {
        return $this->status === DocumentStatus::ToReview;
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, [DocumentStatus::ToReview, DocumentStatus::Verified], true);
    }

    public function hasFile(): bool
    {
        return $this->status !== DocumentStatus::Missing;
    }
}
