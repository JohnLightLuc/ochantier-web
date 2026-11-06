<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\AttendanceCorrectionStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Admin;
use App\Models\AttendanceCorrection;
use Illuminate\Http\Request;

/**
 * Correction d’une journée de pointage dans le Back Office.
 *
 * @mixin AttendanceCorrection
 */
class AttendanceCorrectionResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $day = $this->workDay;
        $author = $this->requestedBy;

        return [
            'id' => $this->id,
            'status' => $this->status($this->status),
            'reason' => $this->reason,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'requested_by' => $author instanceof Admin ? $author->fullName() : null,
            'decided_by' => $this->decidedByAdmin?->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'work_day_id' => $this->work_day_id,
            'date' => $this->date($day->work_date),
            'worker' => ['id' => $day->worker->id, 'reference' => $day->worker->reference, 'full_name' => trim($day->worker->first_name.' '.$day->worker->last_name)],
            'site' => ['id' => $day->mission->site->id, 'label' => $day->mission->site->label],
            'is_pending' => $this->status === AttendanceCorrectionStatus::Demandee,
            'can_approve' => $this->status === AttendanceCorrectionStatus::Demandee && $this->requested_by_id !== $request->user()?->getKey(),
        ];
    }
}
