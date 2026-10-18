<?php

namespace App\Models;

use App\Enums\AnomalyDetector;
use App\Enums\AttendanceAnomalyStatus;
use App\Enums\AttendanceAnomalyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anomalie de pointage (absence, retard, hors zone, preuve manquante…).
 */
#[Fillable([
    'assignment_id', 'work_day_id', 'anomaly_type', 'detected_by', 'reported_by_type', 'reported_by_id',
    'occurred_at', 'comment', 'media_file_id', 'status', 'resolution', 'resolved_by_admin_id', 'resolved_at',
])]
class AttendanceAnomaly extends Model
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
            'anomaly_type' => AttendanceAnomalyType::class,
            'detected_by' => AnomalyDetector::class,
            'occurred_at' => 'datetime',
            'status' => AttendanceAnomalyStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<WorkDay, $this>
     */
    public function workDay(): BelongsTo
    {
        return $this->belongsTo(WorkDay::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
