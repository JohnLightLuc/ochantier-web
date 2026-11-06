<?php

namespace App\Http\Resources\V1\Admin;

use App\Http\Resources\V1\Resource;
use App\Models\AttendanceAnomaly;
use App\Support\Admin\AttendanceAnomalies;
use Illuminate\Http\Request;

/**
 * Anomalie de pointage dans le Back Office.
 *
 * @mixin AttendanceAnomaly
 */
class AttendanceAnomalyResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $worker = $this->assignment->worker;
        $site = $this->assignment->mission->site;

        return [
            'id' => $this->id,
            'type' => $this->status($this->anomaly_type),
            'status' => $this->status($this->status),
            'detected_by' => $this->status($this->detected_by),
            'comment' => $this->comment,
            'resolution' => $this->resolution,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->resolvedByAdmin?->fullName(),
            'work_day_id' => $this->work_day_id,
            'date' => $this->date($this->workDay?->work_date),
            'worker' => ['id' => $worker->id, 'reference' => $worker->reference, 'full_name' => trim($worker->first_name.' '.$worker->last_name)],
            'site' => ['id' => $site->id, 'label' => $site->label],
            'is_open' => in_array($this->status, AttendanceAnomalies::OPEN, true),
        ];
    }
}
