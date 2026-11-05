<?php

namespace App\Support\Promoter;

use App\Enums\AnomalyDetector;
use App\Enums\AssignmentStatus;
use App\Enums\AttendanceAnomalyStatus;
use App\Enums\AttendanceAnomalyType;
use App\Enums\AttendanceEventStatus;
use App\Enums\AttendanceEventType;
use App\Enums\MissionStatus;
use App\Enums\NeedStatus;
use App\Enums\SiteStatus;
use App\Enums\WorkDayStatus;
use App\Models\Assignment;
use App\Models\AttendanceEvent;
use App\Models\MediaFile;
use App\Models\Mission;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkDay;
use App\Models\WorkerTrade;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Pointage des ouvriers » : arrivées et départs prouvés (photo, signature, position), absences, validation de la
 * journée par le promoteur et démarrage de la mission à la première arrivée.
 *
 * Les brouillons restent sur le téléphone : seuls les pointages soumis arrivent ici. Les règles (précision GPS,
 * tolérances, pause, arrondi) viennent des paramètres attendance.* de la plateforme.
 */
class SiteAttendance
{
    /**
     * Missions sur lesquelles on pointe : acompte payé, pas encore terminées.
     */
    public const OPEN_MISSIONS = [MissionStatus::Confirmee, MissionStatus::EnCours];

    public const ASSIGNMENT_WITH = ['worker.photoMedia', 'worker.workerTrades.trade', 'need.trade', 'replacesAssignment.worker'];

    public function __construct(private MediaStore $media) {}

    /**
     * Affectations du chantier qui couvrent la date : période prévue, pas encore arrêtées (remplacement) ni annulées.
     *
     * @return Collection<int, Assignment>
     */
    public function assignmentsOn(Site $site, CarbonInterface $date): Collection
    {
        return Assignment::query()
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey())->whereNotIn('status', [MissionStatus::EnAttenteAcompte, MissionStatus::Annulee]))
            ->where('status', '!=', AssignmentStatus::Annulee)
            ->where('planned_start_date', '<=', $date->toDateString())
            ->where('planned_end_date', '>=', $date->toDateString())
            ->where(fn ($query) => $query->whereNull('actual_end_at')->orWhere('actual_end_at', '>=', $date->copy()->startOfDay()))
            ->with([...self::ASSIGNMENT_WITH, 'mission'])
            ->get()
            ->pipe(fn (Collection $assignments) => $this->ordered($assignments));
    }

    /**
     * Ordre d’affichage de l’équipe : par besoin (métier), puis par nom.
     *
     * @param  Collection<int, Assignment>  $assignments
     * @return Collection<int, Assignment>
     */
    public function ordered(Collection $assignments): Collection
    {
        return $assignments->sortBy([
            fn (Assignment $a, Assignment $b) => $a->need->reference <=> $b->need->reference,
            fn (Assignment $a, Assignment $b) => $this->name($a) <=> $this->name($b),
        ])->values();
    }

    /**
     * Date d’ouverture du pointage : démarrage prévu de la première mission confirmée du chantier.
     */
    public function opensOn(Site $site): ?CarbonImmutable
    {
        $date = Mission::query()->where('site_id', $site->getKey())->whereIn('status', [...self::OPEN_MISSIONS, MissionStatus::Terminee])->min('planned_start_date');

        return $date ? CarbonImmutable::parse($date) : null;
    }

    /**
     * Feuille de pointage d’une journée : une ligne par ouvrier, compteurs, montant et état de validation.
     *
     * @return array<string, mixed>
     */
    public function day(Site $site, CarbonImmutable $date): array
    {
        $opensOn = $this->opensOn($site);
        $assignments = $this->assignmentsOn($site, $date);
        $days = $this->workDays($assignments, $date);
        $rows = $assignments->map(fn (Assignment $assignment) => $this->row($assignment, $days->get($assignment->getKey())));
        $count = fn (string $state) => $rows->where('state.value', $state)->count();
        $pending = $count('non_pointe') + $count('arrive');
        $validated = $rows->isNotEmpty() && $days->count() === $rows->count() && $days->every(fn (WorkDay $day) => $day->validated_at !== null);
        $amount = (int) $rows->where('state.value', 'termine')->sum('daily_rate');
        $open = $opensOn !== null && $date->greaterThanOrEqualTo($opensOn) && ! $date->isFuture();

        return [
            'date' => $date->toDateString(),
            'label' => ($date->isToday() ? 'Aujourd’hui · ' : '').$date->locale('fr')->translatedFormat('D j M Y'),
            'is_open' => $open,
            'opens_on' => $opensOn?->toDateString(),
            'closed_message' => $open ? null : ($opensOn ? 'Le pointage ouvrira le '.$this->dateLabel($opensOn).', au démarrage du chantier.' : 'Le pointage ouvrira après le paiement de l’acompte.'),
            'stats' => [
                'present' => $count('arrive') + $count('termine'),
                'total' => $rows->count(),
                'absent' => $count('absent'),
                'pending' => $pending,
            ],
            'amount' => $amount,
            'validated' => $validated,
            'validated_at' => $validated ? $days->max('validated_at')?->toIso8601String() : null,
            'can_validate' => $open && ! $validated && $rows->isNotEmpty() && $pending === 0,
            'validation_hint' => match (true) {
                $validated => 'Journée validée · '.($count('termine')).' présent'.($count('termine') > 1 ? 's' : '').' · '.$this->money($amount),
                $pending > 0 => $pending.' pointage'.($pending > 1 ? 's' : '').' à compléter (arrivée ou départ)',
                default => 'Montant de la journée : '.$this->money($amount),
            },
            'rows' => $rows->map(fn (array $row) => [...$row, 'actions' => $open && ! $validated ? $row['actions'] : []])->values()->all(),
        ];
    }

    /**
     * Historique des pointages : journées passées ou validées, de la plus récente à la plus ancienne, et taux de
     * présence de chaque ouvrier.
     *
     * @return array<string, mixed>
     */
    public function history(Site $site): array
    {
        $days = WorkDay::query()
            ->whereHas('mission', fn ($query) => $query->where('site_id', $site->getKey()))
            ->where(fn ($query) => $query->where('work_date', '<', today()->toDateString())->orWhereNotNull('validated_at'))
            ->with(['arrivalEvent', 'departureEvent', 'assignment.worker', 'assignment.need.trade'])
            ->orderByDesc('work_date')
            ->get()
            ->sortBy([
                fn (WorkDay $a, WorkDay $b) => $b->work_date <=> $a->work_date,
                fn (WorkDay $a, WorkDay $b) => $a->assignment->need->reference <=> $b->assignment->need->reference,
                fn (WorkDay $a, WorkDay $b) => $this->name($a->assignment) <=> $this->name($b->assignment),
            ])
            ->values();

        $history = $days->groupBy(fn (WorkDay $day) => $day->work_date->toDateString())->map(function (Collection $items, string $date): array {
            $present = $items->where('status', '!=', WorkDayStatus::Absent)->count();
            $amount = (int) $items->filter(fn (WorkDay $day) => $day->departure_event_id !== null)->sum(fn (WorkDay $day) => (int) $day->assignment->promoter_daily_rate);

            return [
                'date' => $date,
                'label' => CarbonImmutable::parse($date)->locale('fr')->translatedFormat('D j M'),
                'validated' => $items->every(fn (WorkDay $day) => $day->validated_at !== null),
                'present' => $present,
                'total' => $items->count(),
                'presence_rate' => (int) round($present / $items->count() * 100),
                'amount' => $amount,
                'rows' => $items->map(fn (WorkDay $day) => [
                    'assignment_id' => $day->assignment_id,
                    'name' => $this->name($day->assignment),
                    'absent' => $day->status === WorkDayStatus::Absent,
                    'arrival' => $this->time($day->arrivalEvent),
                    'departure' => $this->time($day->departureEvent),
                    'worked_minutes' => $day->worked_minutes,
                    'line' => match (true) {
                        $day->status === WorkDayStatus::Absent => 'Absent',
                        $day->departure_event_id !== null => $this->time($day->arrivalEvent).' → '.$this->time($day->departureEvent).' · '.$this->duration((int) $day->worked_minutes),
                        default => 'Arrivé à '.$this->time($day->arrivalEvent).' · départ non pointé',
                    },
                ])->values()->all(),
            ];
        })->values();

        $rates = $days->groupBy('assignment_id')->map(fn (Collection $items) => [
            'assignment_id' => $items->first()->assignment_id,
            'name' => $this->name($items->first()->assignment),
            'trade' => $items->first()->assignment->need->trade->label,
            'days' => $items->count(),
            'present' => $present = $items->where('status', '!=', WorkDayStatus::Absent)->count(),
            'presence_rate' => (int) round($present / $items->count() * 100),
        ])->values();

        return ['data' => $history->all(), 'meta' => ['days' => $history->count(), 'rates' => $rates->all()]];
    }

    /**
     * Enregistre une arrivée ou un départ. Un client_uuid déjà reçu renvoie le pointage existant (envoi hors
     * connexion rejoué) : le booléen indique si le pointage vient d’être créé.
     *
     * @param  array{assignment_id: string, type: string, client_uuid: string, latitude: float|string, longitude: float|string, accuracy: float|string, device_recorded_at?: string|null, device_id?: string|null, app_version?: string|null}  $data
     * @return array{0: AttendanceEvent, 1: bool}
     */
    public function record(Site $site, User $author, array $data, UploadedFile $photo, UploadedFile $signature): array
    {
        if ($existing = AttendanceEvent::query()->where('client_uuid', $data['client_uuid'])->where('site_id', $site->getKey())->first()) {
            return [$existing, false];
        }

        $type = AttendanceEventType::from($data['type']);
        $at = isset($data['device_recorded_at']) ? CarbonImmutable::parse($data['device_recorded_at'])->setTimezone(config('app.timezone')) : CarbonImmutable::now();
        $date = $at->startOfDay();
        $assignment = $this->pointable($site, $data['assignment_id'], $date);
        $day = $this->workDays(collect([$assignment]), $date)->get($assignment->getKey());
        $name = $this->name($assignment);

        $this->ensureNotValidated($day, $date);

        if ($day?->status === WorkDayStatus::Absent) {
            throw ValidationException::withMessages(['assignment_id' => $name.' est marqué absent ce jour-là.']);
        }

        if ($type === AttendanceEventType::Arrivee && $day?->arrival_event_id) {
            throw ValidationException::withMessages(['type' => 'L’arrivée de '.$name.' est déjà pointée.']);
        }

        if ($type === AttendanceEventType::Depart && ! $day?->arrival_event_id) {
            throw ValidationException::withMessages(['type' => 'Pointez d’abord l’arrivée de '.$name.'.']);
        }

        if ($type === AttendanceEventType::Depart && $day->departure_event_id) {
            throw ValidationException::withMessages(['type' => 'Le départ de '.$name.' est déjà pointé.']);
        }

        if ($type === AttendanceEventType::Depart && $at->lessThan($this->moment($day->arrivalEvent))) {
            throw ValidationException::withMessages(['device_recorded_at' => 'Le départ ne peut pas précéder l’arrivée ('.$this->time($day->arrivalEvent).').']);
        }

        $precision = (float) $this->setting('attendance.gps_precision_meters', 50);

        if ((float) $data['accuracy'] > $precision) {
            throw ValidationException::withMessages(['accuracy' => 'Position trop imprécise (± '.(int) round((float) $data['accuracy']).' m, '.(int) $precision.' m au plus) : réessayez à découvert.']);
        }

        $distance = DB::scalar('SELECT ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) FROM sites WHERE id = ?', [(float) $data['longitude'], (float) $data['latitude'], $site->getKey()]);

        $event = DB::transaction(function () use ($site, $author, $data, $photo, $signature, $type, $at, $date, $assignment, $day, $distance): AttendanceEvent {
            $photoMedia = $this->media->store($photo, 'pointage', $author);
            $signatureMedia = $this->media->store($signature, 'signature', $author);

            $event = AttendanceEvent::query()->create([
                'assignment_id' => $assignment->getKey(),
                'mission_id' => $assignment->mission_id,
                'site_id' => $site->getKey(),
                'worker_id' => $assignment->worker_id,
                'work_date' => $date->toDateString(),
                'event_type' => $type,
                'recorded_at' => now(),
                'device_recorded_at' => $at,
                'author_user_id' => $author->getKey(),
                'photo_media_id' => $photoMedia->getKey(),
                'signature_media_id' => $signatureMedia->getKey(),
                'location' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']],
                'location_accuracy_m' => $data['accuracy'],
                'distance_to_site_m' => $distance !== null ? round((float) $distance, 2) : null,
                'out_of_zone' => $distance !== null && (float) $distance > $site->geofence_radius_m,
                'device_id' => $data['device_id'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'status' => AttendanceEventStatus::Soumis,
                'client_uuid' => $data['client_uuid'],
            ]);

            $day = $type === AttendanceEventType::Arrivee
                ? WorkDay::query()->create([
                    'assignment_id' => $assignment->getKey(),
                    'mission_id' => $assignment->mission_id,
                    'worker_id' => $assignment->worker_id,
                    'work_date' => $date->toDateString(),
                    'arrival_event_id' => $event->getKey(),
                    'status' => WorkDayStatus::EnCours,
                ])
                : tap($day)->update([
                    'departure_event_id' => $event->getKey(),
                    'worked_minutes' => $this->workedMinutes($this->moment($day->arrivalEvent), $at),
                    'status' => WorkDayStatus::AValider,
                ]);

            $this->detectAnomalies($site, $assignment, $day, $event, $photoMedia, $distance);

            if ($type === AttendanceEventType::Arrivee) {
                $this->start($assignment, $author, $at);
            }

            return $event;
        });

        return [$event, true];
    }

    /**
     * « Absent » : aucun pointage d’arrivée ce jour-là. La journée n’est pas payée.
     */
    public function markAbsent(Site $site, User $author, string $assignmentId, CarbonImmutable $date): WorkDay
    {
        $assignment = $this->pointable($site, $assignmentId, $date);
        $day = $this->workDays(collect([$assignment]), $date)->get($assignment->getKey());
        $this->ensureNotValidated($day, $date);

        if ($day?->arrival_event_id) {
            throw ValidationException::withMessages(['assignment_id' => 'L’arrivée de '.$this->name($assignment).' est déjà pointée.']);
        }

        if ($day?->status === WorkDayStatus::Absent) {
            return $day;
        }

        return DB::transaction(function () use ($assignment, $author, $date): WorkDay {
            $day = WorkDay::query()->create([
                'assignment_id' => $assignment->getKey(),
                'mission_id' => $assignment->mission_id,
                'worker_id' => $assignment->worker_id,
                'work_date' => $date->toDateString(),
                'status' => WorkDayStatus::Absent,
            ]);

            $this->anomaly($assignment, $day, AttendanceAnomalyType::Absence, AnomalyDetector::Promoteur, $date, 'Absence déclarée par le promoteur', author: $author);

            return $day;
        });
    }

    /**
     * « Valider la journée » : chaque ouvrier a son départ ou son absence. Les journées validées ne sont plus
     * modifiables ; la progression calendaire des missions est mise à jour.
     *
     * @return array<string, mixed> la feuille de pointage validée
     */
    public function validateDay(Site $site, User $author, CarbonImmutable $date): array
    {
        $sheet = $this->day($site, $date);

        if (! $sheet['is_open']) {
            throw ValidationException::withMessages(['date' => $sheet['closed_message'] ?? 'Le pointage n’est pas ouvert ce jour-là.']);
        }

        if ($sheet['rows'] === []) {
            throw ValidationException::withMessages(['date' => 'Aucun ouvrier n’est attendu ce jour-là.']);
        }

        if ($sheet['validated']) {
            throw ValidationException::withMessages(['date' => 'La journée du '.$this->dateLabel($date).' est déjà validée.']);
        }

        if (! $sheet['can_validate']) {
            throw ValidationException::withMessages(['date' => 'Pointez le départ ou l’absence de chaque ouvrier : '.$sheet['stats']['pending'].' pointage'.($sheet['stats']['pending'] > 1 ? 's' : '').' à compléter.']);
        }

        DB::transaction(function () use ($site, $author, $date): void {
            $assignments = $this->assignmentsOn($site, $date);
            $days = $this->workDays($assignments, $date);

            foreach ($days as $day) {
                $day->update([
                    'status' => $day->status === WorkDayStatus::Absent ? WorkDayStatus::Absent : WorkDayStatus::Valide,
                    'validated_by_user_id' => $author->getKey(),
                    'validated_at' => now(),
                ]);
            }

            AttendanceEvent::query()->whereIn('id', $days->flatMap(fn (WorkDay $day) => [$day->arrival_event_id, $day->departure_event_id])->filter())
                ->update(['status' => AttendanceEventStatus::Valide]);

            foreach ($assignments->pluck('mission')->unique('id') as $mission) {
                /** @var Mission $mission */
                $validated = WorkDay::query()->where('mission_id', $mission->getKey())->where('status', WorkDayStatus::Valide)->distinct()->count('work_date');
                $mission->forceFill([
                    'validated_days' => $validated,
                    'calendar_progress' => min(100, round($validated / max(1, $mission->planned_days) * 100, 2)),
                ])->save();
            }
        });

        return $this->day($site, $date);
    }

    /**
     * Ouvrier d’une affectation : identité publique (sans téléphone), métier, tarif et remplacement éventuel.
     *
     * @return array<string, mixed>
     */
    public function member(Assignment $assignment): array
    {
        $worker = $assignment->worker;
        /** @var WorkerTrade|null $trade */
        $trade = $worker->workerTrades->firstWhere('trade_id', $assignment->need->trade_id) ?? $worker->workerTrades->firstWhere('is_primary', true);

        return [
            'assignment_id' => $assignment->id,
            'worker_id' => $worker->id,
            'name' => $this->name($assignment),
            'initials' => mb_strtoupper(mb_substr((string) $worker->first_name, 0, 1).mb_substr((string) $worker->last_name, 0, 1)),
            'photo_url' => $worker->photoMedia ? $this->media->temporaryUrl($worker->photoMedia) : null,
            'trade' => $assignment->need->trade->only(['id', 'code', 'label']),
            'role' => $assignment->role_label ?? $assignment->need->trade->label,
            'experience_years' => $trade?->years_experience,
            'rating' => ['average' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null, 'count' => (int) $worker->rating_count],
            'daily_rate' => $assignment->promoter_daily_rate,
            'since' => ($assignment->actual_start_at ?? $assignment->planned_start_date)->toDateString(),
            'replaces' => $assignment->replacesAssignment ? $this->name($assignment->replacesAssignment) : null,
        ];
    }

    public function name(Assignment $assignment): string
    {
        return trim($assignment->worker->first_name.' '.$assignment->worker->last_name);
    }

    public function dateLabel(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j M Y');
    }

    public function duration(int $minutes): string
    {
        return sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Affectation pointable à cette date : sur ce chantier, mission confirmée ou en cours, pointage ouvert.
     */
    private function pointable(Site $site, string $assignmentId, CarbonImmutable $date): Assignment
    {
        if ($site->status === SiteStatus::Suspendu) {
            throw ValidationException::withMessages(['assignment_id' => 'Ce chantier est suspendu : le pointage reprendra à sa réouverture.']);
        }

        $opensOn = $this->opensOn($site);

        if ($opensOn === null) {
            throw ValidationException::withMessages(['assignment_id' => 'Le pointage ouvrira après le paiement de l’acompte.']);
        }

        if ($date->lessThan($opensOn)) {
            throw ValidationException::withMessages(['assignment_id' => 'Le pointage ouvrira le '.$this->dateLabel($opensOn).', au démarrage du chantier.']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['date' => 'Impossible de pointer une journée à venir.']);
        }

        $assignment = $this->assignmentsOn($site, $date)->firstWhere('id', $assignmentId);

        if (! $assignment || ! in_array($assignment->mission->status, self::OPEN_MISSIONS, true)) {
            throw ValidationException::withMessages(['assignment_id' => 'Cet ouvrier n’est pas affecté à ce chantier ce jour-là.']);
        }

        return $assignment;
    }

    private function ensureNotValidated(?WorkDay $day, CarbonImmutable $date): void
    {
        if ($day?->validated_at !== null) {
            throw ValidationException::withMessages(['date' => 'La journée du '.$this->dateLabel($date).' est déjà validée.']);
        }
    }

    /**
     * @param  Collection<int, Assignment>  $assignments
     * @return Collection<string, WorkDay> par affectation
     */
    private function workDays(Collection $assignments, CarbonInterface $date): Collection
    {
        return WorkDay::query()
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->where('work_date', $date->toDateString())
            ->with(['arrivalEvent.photoMedia', 'arrivalEvent.signatureMedia', 'departureEvent.photoMedia', 'departureEvent.signatureMedia', 'attendanceAnomalies'])
            ->get()
            ->keyBy('assignment_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Assignment $assignment, ?WorkDay $day): array
    {
        [$state, $label] = match (true) {
            $day === null => ['non_pointe', 'Non pointé'],
            $day->status === WorkDayStatus::Absent => ['absent', 'Absent'],
            $day->departure_event_id !== null => ['termine', $this->time($day->arrivalEvent).' → '.$this->time($day->departureEvent).' · '.$this->duration((int) $day->worked_minutes)],
            default => ['arrive', 'Arrivé à '.$this->time($day->arrivalEvent)],
        };

        return [
            ...$this->member($assignment),
            'state' => ['value' => $state, 'label' => $label],
            'arrival' => $this->event($day?->arrivalEvent),
            'departure' => $this->event($day?->departureEvent),
            'worked_minutes' => $day?->worked_minutes,
            'anomalies' => $day ? $day->attendanceAnomalies->map(fn ($anomaly) => ['value' => $anomaly->anomaly_type->value, 'label' => $anomaly->anomaly_type->label(), 'comment' => $anomaly->comment])->values()->all() : [],
            'actions' => match ($state) {
                'non_pointe' => ['arrivee', 'absent'],
                'arrive' => ['depart'],
                default => [],
            },
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function event(?AttendanceEvent $event): ?array
    {
        return $event ? [
            'id' => $event->id,
            'time' => $this->time($event),
            'at' => $this->moment($event)->toIso8601String(),
            'distance_m' => $event->distance_to_site_m !== null ? (int) round((float) $event->distance_to_site_m) : null,
            'out_of_zone' => $event->out_of_zone,
            'photo_url' => $this->media->temporaryUrl($event->photoMedia),
            'signature_url' => $this->media->temporaryUrl($event->signatureMedia),
            'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
        ] : null;
    }

    /**
     * Heure du pointage sur le terminal (envoi hors connexion), sinon heure de réception.
     */
    private function moment(AttendanceEvent $event): CarbonImmutable
    {
        return CarbonImmutable::parse($event->device_recorded_at ?? $event->recorded_at)->setTimezone(config('app.timezone'));
    }

    private function time(?AttendanceEvent $event): ?string
    {
        return $event ? $this->moment($event)->format('H:i') : null;
    }

    /**
     * Temps travaillé : la pause est déduite au-delà de la durée minimale d’une journée, puis arrondi.
     */
    private function workedMinutes(CarbonImmutable $arrival, CarbonImmutable $departure): int
    {
        $gross = (int) $arrival->diffInMinutes($departure);
        $break = (int) $this->setting('attendance.break_minutes', 60);
        $rounding = max(1, (int) $this->setting('attendance.rounding_minutes', 5));
        $net = $gross > (int) $this->setting('attendance.min_day_minutes', 240) ? $gross - $break : $gross;

        return (int) (round(max(0, $net) / $rounding) * $rounding);
    }

    /**
     * Anomalies relevées automatiquement : hors zone au-delà de la tolérance, photo déjà utilisée, retard et départ
     * anticipé par rapport aux horaires du besoin ou du chantier.
     */
    private function detectAnomalies(Site $site, Assignment $assignment, WorkDay $day, AttendanceEvent $event, MediaFile $photo, mixed $distance): void
    {
        $at = $this->moment($event);

        if ($distance !== null && (float) $distance > $site->geofence_radius_m + (float) $this->setting('attendance.zone_tolerance_meters', 300)) {
            $this->anomaly($assignment, $day, AttendanceAnomalyType::HorsZone, AnomalyDetector::Systeme, $at, 'Pointage à '.(int) round((float) $distance).' m du chantier');
        }

        if (MediaFile::query()->where('sha256', $photo->sha256)->where('purpose', 'pointage')->whereKeyNot($photo->getKey())->exists()) {
            $this->anomaly($assignment, $day, AttendanceAnomalyType::PhotoReutilisee, AnomalyDetector::Systeme, $at, 'Photo déjà utilisée pour un autre pointage');
        }

        $isArrival = $event->event_type === AttendanceEventType::Arrivee;
        $planned = $isArrival ? ($assignment->need->work_start_time ?? $site->work_start_time) : ($assignment->need->work_end_time ?? $site->work_end_time);

        if ($planned === null) {
            return;
        }

        $reference = $at->setTimeFromTimeString((string) $planned);

        if ($isArrival && $at->greaterThan($reference->addMinutes((int) $this->setting('attendance.late_tolerance_minutes', 10)))) {
            $this->anomaly($assignment, $day, AttendanceAnomalyType::Retard, AnomalyDetector::Systeme, $at, 'Arrivée à '.$at->format('H:i').' (début prévu '.$reference->format('H:i').')');
        }

        if (! $isArrival && $at->lessThan($reference->subMinutes((int) $this->setting('attendance.early_departure_tolerance_minutes', 15)))) {
            $this->anomaly($assignment, $day, AttendanceAnomalyType::DepartAnticipe, AnomalyDetector::Systeme, $at, 'Départ à '.$at->format('H:i').' (fin prévue '.$reference->format('H:i').')');
        }
    }

    private function anomaly(Assignment $assignment, WorkDay $day, AttendanceAnomalyType $type, AnomalyDetector $detector, CarbonInterface $at, string $comment, ?User $author = null): void
    {
        $day->attendanceAnomalies()->create([
            'assignment_id' => $assignment->getKey(),
            'anomaly_type' => $type,
            'detected_by' => $detector,
            'reported_by_type' => $author?->getMorphClass(),
            'reported_by_id' => $author?->getKey(),
            'occurred_at' => $at,
            'comment' => $comment,
            'status' => AttendanceAnomalyStatus::Ouverte,
        ]);
    }

    /**
     * Première arrivée : l’affectation devient active, la mission, le besoin et le chantier passent « en cours ».
     */
    private function start(Assignment $assignment, User $author, CarbonImmutable $at): void
    {
        $actor = ['actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()];

        if ($assignment->status === AssignmentStatus::Confirmee) {
            $assignment->statusHistories()->create(['from_status' => $assignment->status->value, 'to_status' => AssignmentStatus::Active->value, 'reason' => 'Première arrivée pointée', ...$actor]);
            $assignment->forceFill(['status' => AssignmentStatus::Active, 'actual_start_at' => $at])->save();
        }

        $mission = $assignment->mission;

        if ($mission->status === MissionStatus::Confirmee) {
            $mission->statusHistories()->create(['from_status' => $mission->status->value, 'to_status' => MissionStatus::EnCours->value, 'reason' => 'Première arrivée pointée', ...$actor]);
            $mission->forceFill(['status' => MissionStatus::EnCours, 'actual_start_date' => $at->toDateString()])->save();
        }

        $need = $assignment->need;

        if ($need->status === NeedStatus::Confirme) {
            $need->statusHistories()->create(['from_status' => $need->status->value, 'to_status' => NeedStatus::EnCours->value, 'reason' => 'Première arrivée pointée', ...$actor]);
            $need->forceFill(['status' => NeedStatus::EnCours])->save();
        }

        $site = $mission->site;

        if ($site->status === SiteStatus::Confirme) {
            $site->statusHistories()->create(['from_status' => $site->status, 'to_status' => SiteStatus::EnCours, 'reason' => 'Première arrivée pointée', ...$actor]);
            $site->forceFill(['status' => SiteStatus::EnCours])->save();
            app(PromoterNotifier::class)->siteStarted($site);
        }
    }

    private function setting(string $key, mixed $default): mixed
    {
        return once(fn () => Setting::query()->where('key', 'like', 'attendance.%')->pluck('value', 'key'))->get($key) ?? $default;
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }
}
