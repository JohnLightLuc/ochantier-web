<?php

namespace App\Support\Contractor;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\AttendanceEventStatus;
use App\Enums\WorkDayStatus;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\Contractor;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkDay;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\SiteAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pointage de l’équipe de l’entrepreneur (onglet « Pointage » du chantier) : feuille du jour, arrivées et départs
 * avec position, photo et signature, absences, validation de la journée (synthèse transmise au promoteur),
 * historique, fiche d’une journée et correction motivée.
 *
 * Le pointage reprend les règles de l’application Promoteur (SiteAttendance). Une correction de l’entrepreneur est
 * appliquée tout de suite et tracée (ancienne et nouvelle valeur, motif) ; une journée facturée ne se corrige plus.
 */
class ContractorAttendance
{
    public function __construct(private SiteAttendance $attendance) {}

    /**
     * Journée validée : la synthèse est transmise au promoteur.
     *
     * @return array<string, mixed>
     */
    public function validateDay(Site $site, User $author, CarbonImmutable $date): array
    {
        $sheet = $this->attendance->validateDay($site, $author, $date);

        app(PromoterNotifier::class)->notify($site->promoterProfile, 'attendance.validated', 'Pointage du '.$date->format('d/m/Y').' validé',
            $site->label.' : '.$sheet['stats']['present'].'/'.$sheet['stats']['total'].' présents, journée validée par l’entrepreneur.',
            $site, ['type' => 'site_attendance', 'id' => $site->id, 'date' => $date->toDateString()], null, 'attendance.validated:'.$site->id.':'.$date->toDateString());

        return $sheet;
    }

    /**
     * Journée pointée d’un ouvrier de l’équipe.
     */
    public function findDay(Contractor $contractor, Site $site, string $id): WorkDay
    {
        $day = Str::isUuid($id)
            ? WorkDay::query()->with(['arrivalEvent.photoMedia', 'arrivalEvent.signatureMedia', 'arrivalEvent.authorUser', 'departureEvent.photoMedia', 'departureEvent.signatureMedia', 'assignment.worker.photoMedia', 'assignment.worker.workerTrades.trade', 'assignment.need.trade', 'assignment.replacesAssignment.worker', 'attendanceCorrections', 'attendanceAnomalies'])
                ->whereKey($id)
                ->whereHas('mission', fn (Builder $query) => $query->where('site_id', $site->getKey())->where('contractor_id', $contractor->getKey()))
                ->first()
            : null;

        abort_unless($day, 404, 'Pointage introuvable.');

        return $day;
    }

    /**
     * Fiche d’une journée pointée : arrivée et départ (heure, distance, photo, signature), durée, observation,
     * anomalies et historique des corrections.
     *
     * @return array<string, mixed>
     */
    public function detail(WorkDay $day): array
    {
        $absent = $day->status === WorkDayStatus::Absent;

        return [
            'id' => $day->id,
            'date' => $day->work_date->toDateString(),
            'label' => CarbonImmutable::parse($day->work_date)->locale('fr')->translatedFormat('l j F Y'),
            'member' => $this->attendance->member($day->assignment),
            'state' => match (true) {
                $absent => ['value' => 'absent', 'label' => 'Absent'],
                $day->attendanceAnomalies->contains(fn ($anomaly) => $anomaly->anomaly_type->value === 'retard') => ['value' => 'retard', 'label' => 'En retard'],
                default => ['value' => 'present', 'label' => 'Présent'],
            },
            'status' => ['value' => $day->status->value, 'label' => $day->status->label()],
            'arrival' => $this->attendance->event($day->arrivalEvent),
            'departure' => $this->attendance->event($day->departureEvent),
            'worked_minutes' => $day->worked_minutes,
            'duration' => $day->worked_minutes !== null ? $this->attendance->duration((int) $day->worked_minutes) : null,
            'observation' => $day->observation,
            'author' => $day->arrivalEvent?->authorUser ? 'Saisi par '.trim($day->arrivalEvent->authorUser->first_name.' '.$day->arrivalEvent->authorUser->last_name) : null,
            'validated_at' => $day->validated_at?->toIso8601String(),
            'anomalies' => $day->attendanceAnomalies->map(fn ($anomaly) => ['value' => $anomaly->anomaly_type->value, 'label' => $anomaly->anomaly_type->label(), 'comment' => $anomaly->comment])->values()->all(),
            'corrections' => $day->attendanceCorrections->sortByDesc('created_at')->map(fn (AttendanceCorrection $correction) => [
                'reason' => $correction->reason,
                'old_values' => $correction->old_values,
                'new_values' => $correction->new_values,
                'at' => $correction->created_at?->toIso8601String(),
            ])->values()->all(),
            'can_correct' => ! $day->is_locked,
        ];
    }

    /**
     * « Motif de la correction » : heures d’arrivée et de départ, passage en absence, observation. L’ancienne valeur
     * est conservée dans l’historique ; les pointages corrigés sont remplacés (même photo et signature).
     *
     * @param  array{reason: string, arrival_time?: string|null, departure_time?: string|null, absent?: bool, observation?: string|null}  $data
     */
    public function correct(WorkDay $day, User $author, array $data): WorkDay
    {
        if ($day->is_locked) {
            throw ValidationException::withMessages(['attendance' => 'Cette journée est facturée : elle ne peut plus être corrigée.']);
        }

        $old = $this->values($day);
        $absent = (bool) ($data['absent'] ?? false);

        if ($day->status === WorkDayStatus::Absent && ! $absent && (isset($data['arrival_time']) || isset($data['departure_time']))) {
            throw ValidationException::withMessages(['arrival_time' => 'Une absence ne peut pas être transformée en présence sans pointage : contactez l’assistance O’CHANTIER.']);
        }

        if (isset($data['departure_time']) && ! $day->departure_event_id) {
            throw ValidationException::withMessages(['departure_time' => 'Pointez d’abord le départ de l’ouvrier.']);
        }

        DB::transaction(function () use ($day, $author, $data, $absent): void {
            if ($absent && $day->status !== WorkDayStatus::Absent) {
                AttendanceEvent::query()->whereKey(array_filter([$day->arrival_event_id, $day->departure_event_id]))->update(['status' => AttendanceEventStatus::Annule->value]);
                $day->forceFill(['status' => WorkDayStatus::Absent, 'arrival_event_id' => null, 'departure_event_id' => null, 'worked_minutes' => null, 'validated_at' => null])->save();
            }

            if (! $absent) {
                $date = CarbonImmutable::parse($day->work_date);
                $at = fn (?string $time, ?AttendanceEvent $event) => $time !== null ? $date->setTime(...array_map('intval', explode(':', $time))) : ($event ? $this->attendance->moment($event) : null);
                $arrivalAt = $at($data['arrival_time'] ?? null, $day->arrivalEvent);
                $departureAt = $at($data['departure_time'] ?? null, $day->departureEvent);

                if ($arrivalAt && $departureAt && $departureAt->lte($arrivalAt)) {
                    throw ValidationException::withMessages(['departure_time' => 'Le départ doit suivre l’arrivée.']);
                }

                $arrival = isset($data['arrival_time']) ? $this->replace($day->arrivalEvent, $arrivalAt, $author) : $day->arrivalEvent;
                $departure = isset($data['departure_time']) ? $this->replace($day->departureEvent, $departureAt, $author) : $day->departureEvent;

                $day->forceFill([
                    'arrival_event_id' => $arrival?->getKey(),
                    'departure_event_id' => $departure?->getKey(),
                    'worked_minutes' => $arrivalAt && $departureAt ? $this->attendance->workedMinutes($arrivalAt, $departureAt) : $day->worked_minutes,
                ])->save();
            }

            if (array_key_exists('observation', $data)) {
                $day->forceFill(['observation' => $data['observation']])->save();
            }
        });

        $new = $this->values($day->refresh());

        if ($new == $old) {
            throw ValidationException::withMessages(['attendance' => 'Indiquez ce qui doit changer.']);
        }

        $day->attendanceCorrections()->create([
            'requested_by_type' => $author->getMorphClass(),
            'requested_by_id' => $author->getKey(),
            'reason' => trim($data['reason']),
            'old_values' => $old,
            'new_values' => $new,
            'status' => AttendanceCorrectionStatus::Approuvee,
            'decided_at' => now(),
        ]);

        $this->attendance->refreshProgress($day->mission);

        return $day->refresh();
    }

    /**
     * Nouveau pointage à l’heure corrigée, qui remplace l’ancien (conservé, statut « corrigé »).
     */
    private function replace(?AttendanceEvent $event, CarbonImmutable $at, User $author): AttendanceEvent
    {
        if (! $event) {
            throw ValidationException::withMessages(['arrival_time' => 'Ce pointage n’existe pas encore.']);
        }

        $status = $event->status;
        $event->forceFill(['status' => AttendanceEventStatus::Corrige])->save();

        $corrected = $event->replicate(['id', 'client_uuid', 'created_at', 'superseded_by_id']);
        $corrected->forceFill([
            'device_recorded_at' => $at,
            'recorded_at' => now(),
            'author_user_id' => $author->getKey(),
            'status' => $status,
            'client_uuid' => (string) Str::uuid(),
        ])->save();
        $event->forceFill(['superseded_by_id' => $corrected->getKey()])->save();

        return $corrected;
    }

    /**
     * @return array{status: string, arrival: string|null, departure: string|null, worked_minutes: int|null, observation: string|null}
     */
    private function values(WorkDay $day): array
    {
        $day->load(['arrivalEvent', 'departureEvent']);

        return [
            'status' => $day->status->value,
            'arrival' => $this->attendance->time($day->arrivalEvent),
            'departure' => $this->attendance->time($day->departureEvent),
            'worked_minutes' => $day->worked_minutes,
            'observation' => $day->observation,
        ];
    }
}
