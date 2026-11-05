<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\WorkDayStatus;
use App\Models\Assignment;
use App\Models\Replacement;
use App\Models\UnavailabilityRequest;
use App\Support\Admin\AssignmentDirectory;
use App\Support\Matching\ReplacementSearch;
use Illuminate\Http\Request;

/**
 * Fiche d’une affectation : période, tarifs figés, jours pointés, indisponibilités et remplacements.
 *
 * @mixin Assignment
 */
class AssignmentDetailResource extends AssignmentListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $days = $this->workDays()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $replacedBy = Replacement::query()->where('original_assignment_id', $this->id)->with(['replacementAssignment.worker'])->latest()->get();
        $replaces = $this->replacesAssignment?->worker;
        $open = $replacedBy->firstWhere('id', $this->open_replacement_id);

        return [
            ...parent::toArray($request),
            'actual_start_at' => $this->actual_start_at?->toIso8601String(),
            'actual_end_at' => $this->actual_end_at?->toIso8601String(),
            'end_reason' => $this->end_reason,
            'replaces' => $replaces ? ['id' => $this->replaces_assignment_id, 'worker' => trim($replaces->first_name.' '.$replaces->last_name)] : null,
            'days' => ['validated' => (int) $days->get(WorkDayStatus::Valide->value, 0), 'pending' => (int) $days->get(WorkDayStatus::AValider->value, 0), 'absent' => (int) $days->get(WorkDayStatus::Absent->value, 0)],
            'unavailabilities' => $this->unavailabilityRequests()->with('reason')->latest()->get()->map(fn (UnavailabilityRequest $item) => [
                'id' => $item->id,
                'status' => $this->status($item->status),
                'declared_by' => $this->status($item->declared_by_type),
                'reason' => $item->reason?->label,
                'comment' => $item->reason_comment,
                'effective_at' => $item->effective_at?->toIso8601String(),
                'is_urgent' => (bool) $item->is_urgent,
            ])->all(),
            'replacements' => $replacedBy->map(fn (Replacement $item) => [
                'id' => $item->id,
                'status' => $this->status($item->status),
                'replaced_by' => $item->replacementAssignment ? ['id' => $item->replacementAssignment->worker_id, 'full_name' => trim($item->replacementAssignment->worker->first_name.' '.$item->replacementAssignment->worker->last_name)] : null,
                'created_at' => $item->created_at?->toIso8601String(),
            ])->all(),
            'search' => $open ? app(ReplacementSearch::class)->present($open) : null,
            'can' => ['declare_unavailability' => in_array($this->status, AssignmentDirectory::OPEN, true) && $this->open_replacement_id === null && $this->planned_end_date->gte(today()->addDay())],
        ];
    }
}
