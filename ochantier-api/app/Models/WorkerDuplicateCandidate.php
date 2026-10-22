<?php

namespace App\Models;

use App\Casts\PostgresArray;
use App\Enums\DuplicateCandidateStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doublon potentiel détecté entre deux dossiers d’ouvriers.
 */
class WorkerDuplicateCandidate extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'worker_id', 'duplicate_worker_id', 'match_criteria', 'score', 'status', 'resolved_by_admin_id', 'resolved_at',
    ];

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_criteria' => PostgresArray::class,
            'score' => 'decimal:2',
            'status' => DuplicateCandidateStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function duplicateWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
