<?php

namespace App\Models;

use App\Enums\AttendanceCorrectionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Correction motivée : l'ancienne valeur est conservée.
 */
class AttendanceCorrection extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attendance_event_id', 'work_day_id', 'requested_by_type', 'requested_by_id', 'reason', 'old_values',
        'new_values', 'status', 'decided_by_admin_id', 'decided_at',
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
            'old_values' => 'array',
            'new_values' => 'array',
            'status' => AttendanceCorrectionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttendanceEvent, $this>
     */
    public function attendanceEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }

    /**
     * @return BelongsTo<WorkDay, $this>
     */
    public function workDay(): BelongsTo
    {
        return $this->belongsTo(WorkDay::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function decidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
