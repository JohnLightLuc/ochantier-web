<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/**
 * Journée de pointage. Les preuves (photos, signatures) sont résumées : type, heure et statut.
 *
 * @mixin \App\Models\Attendance
 */
class AttendanceResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'date' => $this->date($this->date),
            'assignment_reference' => $this->assignment_reference,
            'planned_start' => $this->planned_start,
            'planned_end' => $this->planned_end,
            'night' => $this->night,
            'arrival' => $this->event($this->arrival),
            'departure' => $this->event($this->departure),
            'pauses' => $this->pauses,
            'absence' => $this->absence,
            'lateness' => $this->lateness,
            'early_leave' => $this->early_leave,
            'overtime' => $this->overtime,
            'closed' => $this->isClosed(),
            'closure' => $this->closure['status'] ?? null,
            'transmission' => $this->transmission,
            'site' => $this->whenLoaded('site', fn (): array => ['slug' => $this->site->slug, 'name' => $this->site->name, 'commune' => $this->site->commune]),
            'worker' => $this->whenLoaded('worker', fn (): array => ['slug' => $this->worker->slug, 'code' => $this->worker->code, 'name' => $this->worker->name, 'trade' => $this->worker->trade, 'photo_url' => $this->worker->photo_path ? asset('storage/'.$this->worker->photo_path) : null]),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function event(array $event): array
    {
        return [
            'status' => $event['status'] ?? null,
            'time' => $event['time'] ?? null,
            'by' => $event['by'] ?? null,
            'validated_by' => $event['validated_by'] ?? null,
            'validated_at' => $event['validated_at'] ?? null,
            'location' => isset($event['location']) ? ['status' => $event['location']['status'] ?? null, 'meters' => $event['location']['meters'] ?? null] : null,
            'proofs' => array_map(fn (array $p): array => ['type' => $p['type'], 'at' => $p['at'] ?? null, 'status' => $p['status'] ?? null], $event['proofs'] ?? []),
            'comment' => $event['comment'] ?? null,
        ];
    }
}
