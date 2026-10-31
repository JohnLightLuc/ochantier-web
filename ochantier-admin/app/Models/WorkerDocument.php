<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\WorkerDocumentType;
use Database\Factories\WorkerDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce justificative du dossier d’un ouvrier.
 */
class WorkerDocument extends Model
{
    /** @use HasFactory<WorkerDocumentFactory> */
    use HasFactory;

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
            'type' => WorkerDocumentType::class,
            'status' => DocumentStatus::class,
            'added_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function hasFile(): bool
    {
        return $this->status !== DocumentStatus::Missing;
    }
}
