<?php

namespace App\Models;

use App\Enums\WorkDayStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Journée travaillée : unité payable.
 */
class WorkDay extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'assignment_id', 'mission_id', 'worker_id', 'work_date', 'arrival_event_id', 'departure_event_id',
        'worked_minutes', 'status', 'observation', 'validated_by_user_id', 'validated_at', 'is_locked',
        'payment_schedule_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => WorkDayStatus::class,
            'validated_at' => 'datetime',
            'is_locked' => 'boolean',
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
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<AttendanceEvent, $this>
     */
    public function arrivalEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }

    /**
     * @return BelongsTo<AttendanceEvent, $this>
     */
    public function departureEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PaymentSchedule, $this>
     */
    public function paymentSchedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class);
    }

    /**
     * @return HasMany<AttendanceAnomaly, $this>
     */
    public function attendanceAnomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class);
    }

    /**
     * @return HasMany<AttendanceCorrection, $this>
     */
    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }
}
