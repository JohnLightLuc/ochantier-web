<?php

namespace App\Http\Resources\V1\Admin;

use App\Enums\WorkDayStatus;
use App\Models\AttendanceEvent;
use App\Models\WorkDay;
use App\Support\Admin\AttendanceCorrections;
use App\Support\Media\MediaStore;
use Illuminate\Http\Request;

/**
 * Fiche d’une journée : preuves d’arrivée et de départ, validation, anomalies et corrections.
 *
 * @mixin WorkDay
 */
class AttendanceDetailResource extends AttendanceListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'assignment_id' => $this->assignment_id,
            'observation' => $this->observation,
            'validated_at' => $this->validated_at?->toIso8601String(),
            'validated_by' => $this->validatedByUser ? trim($this->validatedByUser->first_name.' '.$this->validatedByUser->last_name) : null,
            'events' => [
                'arrival' => $this->proof($this->arrivalEvent),
                'departure' => $this->proof($this->departureEvent),
            ],
            'anomalies' => AttendanceAnomalyResource::collection($this->attendanceAnomalies()->with(['assignment.worker', 'assignment.mission.site', 'workDay', 'resolvedByAdmin'])->oldest()->get())->resolve($request),
            'corrections' => AttendanceCorrectionResource::collection($this->attendanceCorrections()->with(['workDay.worker', 'workDay.mission.site', 'requestedBy', 'decidedByAdmin'])->latest()->get())->resolve($request),
            'can' => ['remind' => $this->status === WorkDayStatus::AValider, 'correct' => app(AttendanceCorrections::class)->options($this->resource)],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function proof(?AttendanceEvent $event): ?array
    {
        if (! $event) {
            return null;
        }

        $media = app(MediaStore::class);

        return [
            'time' => $this->time($event),
            'distance_m' => $event->distance_to_site_m !== null ? (int) round((float) $event->distance_to_site_m) : null,
            'accuracy_m' => $event->location_accuracy_m !== null ? (int) round((float) $event->location_accuracy_m) : null,
            'out_of_zone' => (bool) $event->out_of_zone,
            'author' => trim($event->authorUser?->first_name.' '.$event->authorUser?->last_name),
            'photo_url' => $media->temporaryUrl($event->photoMedia),
            'signature_url' => $media->temporaryUrl($event->signatureMedia),
            'status' => $this->status($event->status),
        ];
    }
}
