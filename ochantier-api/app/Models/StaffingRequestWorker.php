<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ouvrier proposé pour un besoin d’une recherche d’effectifs (proposé, retenu après paiement ou libéré).
 */
class StaffingRequestWorker extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'staffing_request_line_id', 'worker_id', 'status', 'assignment_id',
    ];

    /**
     * @return BelongsTo<StaffingRequestLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StaffingRequestLine::class, 'staffing_request_line_id');
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
