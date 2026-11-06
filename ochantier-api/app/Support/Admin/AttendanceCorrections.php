<?php

namespace App\Support\Admin;

use App\Enums\AttendanceCorrectionStatus;
use App\Enums\PaymentScheduleType;
use App\Enums\WorkDayStatus;
use App\Models\Admin;
use App\Models\AttendanceCorrection;
use App\Models\Mission;
use App\Models\PaymentSchedule;
use App\Models\WorkDay;
use App\Support\Promoter\SiteAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrections d’une journée de pointage : un opérateur demande, une autre personne approuve. L’ancienne valeur reste
 * conservée. Seules la durée et la reconnaissance d’une journée validée se corrigent : une absence ne devient pas une
 * présence, car une journée validée exige des pointages prouvés (photo, signature, position).
 */
class AttendanceCorrections
{
    public const STATUS_LABELS = ['valide' => 'Validé', 'rejete' => 'Rejeté', 'a_valider' => 'À valider', 'en_cours' => 'En cours', 'absent' => 'Absent', 'annule' => 'Annulé'];

    public function __construct(private SiteAttendance $attendance) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AttendanceCorrection>
     */
    public function query(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return AttendanceCorrection::query()
            ->whereNotNull('work_day_id')
            ->with(['workDay.worker', 'workDay.mission.site', 'requestedBy', 'decidedByAdmin'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($search, fn (Builder $query) => $query->whereHas('workDay', fn (Builder $query) => $query
                ->whereHas('worker', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])->orWhere('reference', 'ilike', $search))
                ->orWhereHas('mission.site', fn (Builder $query) => $query->where('label', 'ilike', $search))))
            ->orderByRaw("case status when 'demandee' then 0 else 1 end")
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $rows = AttendanceCorrection::query()->whereNotNull('work_day_id')->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(AttendanceCorrectionStatus::cases())->mapWithKeys(fn (AttendanceCorrectionStatus $status) => [$status->value => (int) $rows->get($status->value, 0)])->all();
    }

    /**
     * Ce qui peut être demandé sur cette journée.
     *
     * @return array{minutes: bool, status: string|null}
     */
    public function options(WorkDay $day): array
    {
        if ($day->is_locked || $day->attendanceCorrections()->where('status', AttendanceCorrectionStatus::Demandee)->exists()) {
            return ['minutes' => false, 'status' => null];
        }

        return [
            'minutes' => $day->departure_event_id !== null && in_array($day->status, [WorkDayStatus::AValider, WorkDayStatus::Valide], true),
            'status' => match (true) {
                $day->status === WorkDayStatus::Valide => WorkDayStatus::Rejete->value,
                $day->status === WorkDayStatus::Rejete && $day->arrival_event_id && $day->departure_event_id && $day->validated_by_user_id => WorkDayStatus::Valide->value,
                default => null,
            },
        ];
    }

    public function request(WorkDay $day, ?int $minutes, ?string $status, string $reason, Admin $admin, Request $request): AttendanceCorrection
    {
        $this->ensureEditable($day);
        $options = $this->options($day);
        $new = [];

        if ($minutes !== null && $minutes !== $day->worked_minutes) {
            $options['minutes'] ?: throw ValidationException::withMessages(['attendance' => 'La durée de cette journée ne peut pas être corrigée.']);
            $new['worked_minutes'] = $minutes;
        }

        if ($status !== null) {
            $status === $options['status'] ?: throw ValidationException::withMessages(['attendance' => 'Ce changement de statut n’est pas possible sur cette journée.']);
            $new['status'] = $status;
        }

        if ($new === []) {
            throw ValidationException::withMessages(['attendance' => 'Indiquez ce qui doit changer.']);
        }

        $correction = $day->attendanceCorrections()->create([
            'requested_by_type' => $admin->getMorphClass(),
            'requested_by_id' => $admin->getKey(),
            'reason' => $reason,
            'old_values' => $this->values($day),
            'new_values' => $new,
            'status' => AttendanceCorrectionStatus::Demandee,
        ]);

        AdminAudit::record($admin, 'attendance', 'attendance.correction_requested', $request, $correction, $this->values($day), $new);

        return $correction;
    }

    public function approve(AttendanceCorrection $correction, Admin $admin, Request $request): void
    {
        $this->ensurePending($correction);

        if ($correction->requested_by_id === $admin->getKey()) {
            throw ValidationException::withMessages(['attendance' => 'Une autre personne doit approuver cette correction.']);
        }

        $day = $correction->workDay;
        $this->ensureEditable($day);

        if ($this->values($day) != $correction->old_values) {
            throw ValidationException::withMessages(['attendance' => 'La journée a changé depuis la demande : refusez cette correction et faites-en une nouvelle.']);
        }

        DB::transaction(function () use ($correction, $day, $admin): void {
            $day->update($correction->new_values);
            $correction->update(['status' => AttendanceCorrectionStatus::Approuvee, 'decided_by_admin_id' => $admin->getKey(), 'decided_at' => now()]);
            $this->attendance->refreshProgress(Mission::query()->findOrFail($day->mission_id));
        });

        AdminAudit::record($admin, 'attendance', 'attendance.correction_approved', $request, $correction, $correction->old_values, $correction->new_values);
    }

    public function refuse(AttendanceCorrection $correction, Admin $admin, Request $request): void
    {
        $this->ensurePending($correction);
        $correction->update(['status' => AttendanceCorrectionStatus::Refusee, 'decided_by_admin_id' => $admin->getKey(), 'decided_at' => now()]);
        AdminAudit::record($admin, 'attendance', 'attendance.correction_refused', $request, $correction);
    }

    /**
     * @return array{status: string, worked_minutes: int|null}
     */
    private function values(WorkDay $day): array
    {
        return ['status' => $day->status->value, 'worked_minutes' => $day->worked_minutes];
    }

    private function ensureEditable(WorkDay $day): void
    {
        if ($day->is_locked) {
            throw ValidationException::withMessages(['attendance' => 'Cette journée est facturée : elle ne peut plus être corrigée.']);
        }

        if (PaymentSchedule::query()->where('mission_id', $day->mission_id)->where('schedule_type', PaymentScheduleType::Regularisation)->exists()) {
            throw ValidationException::withMessages(['attendance' => 'Cette mission est régularisée : ses journées ne peuvent plus être corrigées.']);
        }
    }

    private function ensurePending(AttendanceCorrection $correction): void
    {
        if ($correction->status !== AttendanceCorrectionStatus::Demandee) {
            throw ValidationException::withMessages(['attendance' => 'Cette correction a déjà été traitée.']);
        }
    }
}
