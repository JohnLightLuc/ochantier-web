<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ReportFrequency;
use App\Enums\ReportScheduleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rapport planifié envoyé automatiquement.
 */
#[Fillable([
    'report_kind', 'name', 'frequency', 'next_run_at', 'recipients', 'format', 'filters', 'status',
    'created_by_admin_id',
])]
class ReportSchedule extends Model
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
            'frequency' => ReportFrequency::class,
            'next_run_at' => 'datetime',
            'recipients' => 'array',
            'format' => ExportFormat::class,
            'filters' => 'array',
            'status' => ReportScheduleStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return HasMany<ReportRun, $this>
     */
    public function reportRuns(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'schedule_id');
    }
}
