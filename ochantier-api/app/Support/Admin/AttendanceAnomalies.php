<?php

namespace App\Support\Admin;

use App\Enums\AnomalyDetector;
use App\Enums\AttendanceAnomalyStatus;
use App\Enums\AttendanceAnomalyType;
use App\Models\Admin;
use App\Models\AttendanceAnomaly;
use App\Models\WorkDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Anomalies de pointage : celles détectées au pointage, déclarées par le promoteur ou signalées par un opérateur, puis
 * traitées avec une résolution écrite. Une anomalie ne bloque ni la validation ni le paiement.
 */
class AttendanceAnomalies
{
    public const OPEN = [AttendanceAnomalyStatus::Ouverte, AttendanceAnomalyStatus::EnTraitement];

    /**
     * Types qu’un opérateur peut signaler : une absence se déclare au pointage.
     */
    public const REPORTABLE = [AttendanceAnomalyType::Retard, AttendanceAnomalyType::DepartAnticipe, AttendanceAnomalyType::Doublon, AttendanceAnomalyType::HorsZone, AttendanceAnomalyType::PreuveManquante, AttendanceAnomalyType::IncoherenceHoraire, AttendanceAnomalyType::PhotoReutilisee];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AttendanceAnomaly>
     */
    public function query(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return AttendanceAnomaly::query()
            ->with(['assignment.worker', 'assignment.mission.site', 'workDay', 'resolvedByAdmin'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('anomaly_type', $type))
            ->when($search, fn (Builder $query) => $query->whereHas('assignment', fn (Builder $query) => $query
                ->whereHas('worker', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])->orWhere('reference', 'ilike', $search))
                ->orWhereHas('mission.site', fn (Builder $query) => $query->where('label', 'ilike', $search))))
            ->orderByRaw("case status when 'ouverte' then 0 when 'en_traitement' then 1 else 2 end")
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $rows = AttendanceAnomaly::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(AttendanceAnomalyStatus::cases())->mapWithKeys(fn (AttendanceAnomalyStatus $status) => [$status->value => (int) $rows->get($status->value, 0)])->all();
    }

    public function report(WorkDay $day, AttendanceAnomalyType $type, string $comment, Admin $admin, Request $request): AttendanceAnomaly
    {
        $anomaly = $day->attendanceAnomalies()->create([
            'assignment_id' => $day->assignment_id,
            'anomaly_type' => $type,
            'detected_by' => AnomalyDetector::Admin,
            'reported_by_type' => $admin->getMorphClass(),
            'reported_by_id' => $admin->getKey(),
            'occurred_at' => $day->work_date,
            'comment' => $comment,
            'status' => AttendanceAnomalyStatus::Ouverte,
        ]);

        AdminAudit::record($admin, 'attendance', 'attendance.anomaly_reported', $request, $anomaly, null, ['type' => $type->value]);

        return $anomaly;
    }

    public function start(AttendanceAnomaly $anomaly, Admin $admin, Request $request): void
    {
        if ($anomaly->status !== AttendanceAnomalyStatus::Ouverte) {
            throw ValidationException::withMessages(['anomaly' => 'Cette anomalie est déjà en traitement ou clôturée.']);
        }

        $anomaly->update(['status' => AttendanceAnomalyStatus::EnTraitement]);
        AdminAudit::record($admin, 'attendance', 'attendance.anomaly_started', $request, $anomaly);
    }

    /**
     * Clôture : résolue si l’anomalie est confirmée et réglée, rejetée si elle n’en est pas une.
     */
    public function close(AttendanceAnomaly $anomaly, AttendanceAnomalyStatus $outcome, string $resolution, Admin $admin, Request $request): void
    {
        if (! in_array($anomaly->status, self::OPEN, true)) {
            throw ValidationException::withMessages(['anomaly' => 'Cette anomalie est déjà clôturée.']);
        }

        $anomaly->update(['status' => $outcome, 'resolution' => $resolution, 'resolved_by_admin_id' => $admin->getKey(), 'resolved_at' => now()]);
        AdminAudit::record($admin, 'attendance', 'attendance.anomaly_closed', $request, $anomaly, null, ['outcome' => $outcome->value, 'reason' => $resolution]);
    }
}
