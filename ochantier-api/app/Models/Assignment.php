<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\SalaryPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Affectation d'un ouvrier à une mission (conditions financières figées).
 */
#[Fillable([
    'mission_id', 'need_id', 'worker_id', 'selection_id', 'team_id', 'role_label', 'is_team_lead', 'commercial_id',
    'planned_start_date', 'planned_end_date', 'actual_start_at', 'actual_end_at', 'promoter_daily_rate',
    'worker_net_daily_rate', 'salary_amount', 'salary_period', 'z_rate', 'status', 'replaces_assignment_id',
    'end_reason',
])]
class Assignment extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_team_lead' => 'boolean',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'salary_period' => SalaryPeriod::class,
            'z_rate' => 'decimal:4',
            'status' => AssignmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * @return BelongsTo<Need, $this>
     */
    public function need(): BelongsTo
    {
        return $this->belongsTo(Need::class);
    }

    /**
     * @return BelongsTo<Worker, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * @return BelongsTo<Selection, $this>
     */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<CommercialProfile, $this>
     */
    public function commercial(): BelongsTo
    {
        return $this->belongsTo(CommercialProfile::class);
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function replacesAssignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return HasMany<AssignmentStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(AssignmentStatusHistory::class);
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    /**
     * @return HasMany<WorkDay, $this>
     */
    public function workDays(): HasMany
    {
        return $this->hasMany(WorkDay::class);
    }

    /**
     * @return HasMany<AttendanceAnomaly, $this>
     */
    public function attendanceAnomalies(): HasMany
    {
        return $this->hasMany(AttendanceAnomaly::class);
    }

    /**
     * @return HasMany<UnavailabilityRequest, $this>
     */
    public function unavailabilityRequests(): HasMany
    {
        return $this->hasMany(UnavailabilityRequest::class);
    }

    /**
     * @return HasMany<PaymentScheduleLine, $this>
     */
    public function paymentScheduleLines(): HasMany
    {
        return $this->hasMany(PaymentScheduleLine::class);
    }

    /**
     * @return HasMany<Rating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /**
     * @return HasMany<Dispute, $this>
     */
    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }
}
