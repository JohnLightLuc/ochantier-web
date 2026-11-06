<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pointages : liste et fiche d’une journée, lues dans l’API (api/v1/admin/attendance), une ligne par ouvrier et par
 * jour. La validation reste celle du promoteur ; le Back Office peut le relancer traite les anomalies et les corrections.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class AttendanceController extends Controller
{
    private const PER_PAGE = 20;

    public const STATUS_TONES = ['en_cours' => 'b', 'a_valider' => 'a', 'valide' => 'g', 'rejete' => 'r', 'absent' => 'r', 'annule' => 'n'];

    public const STATUS_LABELS = ['en_cours' => 'En cours', 'a_valider' => 'À valider', 'valide' => 'Validé', 'rejete' => 'Rejeté', 'absent' => 'Absent', 'annule' => 'Annulé'];

    private const PERIODS = ['aujourdhui' => 'Aujourd’hui', 'hier' => 'Hier', '7j' => '7 derniers jours', '30j' => '30 derniers jours'];

    private const QUERY = ['q' => 'search', 'statut' => 'status', 'metier' => 'trade_id', 'localisation' => 'commune_id', 'rapide' => 'todo', 'chantier' => 'site_id', 'ouvrier' => 'worker_id', 'page' => 'page'];

    private const SORTS = ['date' => 'date', 'ouvrier' => 'worker', 'statut' => 'status', 'duree' => 'minutes'];

    private const FILTER_KEYS = ['q', 'statut', 'metier', 'localisation', 'rapide', 'chantier', 'ouvrier', 'periode', 'tri', 'sens', 'page'];

    private const REPORT_TYPES = [['value' => 'retard', 'label' => 'Retard'], ['value' => 'depart_anticipe', 'label' => 'Départ anticipé'], ['value' => 'doublon', 'label' => 'Doublon'], ['value' => 'hors_zone', 'label' => 'Hors zone'], ['value' => 'preuve_manquante', 'label' => 'Preuve manquante'], ['value' => 'incoherence_horaire', 'label' => 'Incohérence horaire'], ['value' => 'photo_reutilisee', 'label' => 'Photo réutilisée']];

    public const CORRECTION_TONES = ['demandee' => 'a', 'approuvee' => 'g', 'refusee' => 'r'];

    public const ANOMALY_TONES = ['ouverte' => 'r', 'en_traitement' => 'a', 'resolue' => 'g', 'rejetee' => 'n'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $response = $this->api->get('attendance', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Attendances/Index', [
            'attendances' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'periode' => array_map($option, array_keys(self::PERIODS), self::PERIODS),
                'statut' => array_map($option, array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
                'metier' => array_map(fn (array $t): array => $option($t['id'], $t['label']), $meta['trades']),
                'localisation' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
            ],
            'kpis' => ['pending' => $meta['counts']['statuses']['a_valider'], 'validated' => $meta['counts']['statuses']['valide'], 'absent' => $meta['counts']['statuses']['absent'], 'anomalies' => $meta['counts']['todo']['anomalies']],
            'todo' => $meta['counts']['todo'],
            'can' => $this->can($request),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('attendance/export', $this->apiQuery($this->filters($request)));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="pointages.csv"']);
    }

    public function show(Request $request, string $attendance): Response
    {
        $a = $this->api->get("attendance/{$attendance}")->json('data');

        return Inertia::render('Attendances/Show', [
            'attendance' => [
                ...$this->row($a),
                'assignmentId' => $a['assignment_id'],
                'observation' => $a['observation'],
                'validatedAt' => $this->day($a['validated_at']),
                'validatedBy' => $a['validated_by'],
                'arrivalProof' => $this->proof($a['events']['arrival']),
                'departureProof' => $this->proof($a['events']['departure']),
                'anomalies' => array_map($this->anomaly(...), $a['anomalies']),
                'corrections' => array_map($this->correction(...), $a['corrections']),
                'canRemind' => $a['can']['remind'],
                'correct' => $a['can']['correct'],
            ],
            'reportTypes' => self::REPORT_TYPES,
            'can' => $this->can($request),
            'header' => $this->header('Fiche du pointage', '', [['Pointages', '/pointages'], [$a['worker']['full_name']]]),
        ]);
    }

    public function anomalies(Request $request): Response
    {
        $filters = array_filter($request->only(['q', 'statut', 'type', 'page']), fn ($value) => is_string($value) && $value !== '');
        $query = array_filter(['search' => $filters['q'] ?? null, 'status' => $filters['statut'] ?? null, 'type' => $filters['type'] ?? null, 'page' => $filters['page'] ?? null]);
        $response = $this->api->get('attendance/anomalies', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->anomaly(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Attendances/Anomalies', [
            'anomalies' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => [['value' => 'ouverte', 'label' => 'Ouverte'], ['value' => 'en_traitement', 'label' => 'En traitement'], ['value' => 'resolue', 'label' => 'Résolue'], ['value' => 'rejetee', 'label' => 'Rejetée']],
                'type' => $meta['types'],
            ],
            'kpis' => ['open' => $meta['counts']['ouverte'], 'working' => $meta['counts']['en_traitement'], 'resolved' => $meta['counts']['resolue'], 'rejected' => $meta['counts']['rejetee']],
            'can' => $this->can($request),
            'header' => $this->header('Anomalies de pointage', '', [['Pointages', '/pointages'], ['Anomalies']]),
        ]);
    }

    public function corrections(Request $request): Response
    {
        $filters = array_filter($request->only(['q', 'statut', 'page']), fn ($value) => is_string($value) && $value !== '');
        $query = array_filter(['search' => $filters['q'] ?? null, 'status' => $filters['statut'] ?? null, 'page' => $filters['page'] ?? null]);
        $response = $this->api->get('attendance/corrections', [...$query, 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->correction(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Attendances/Corrections', [
            'corrections' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => ['statut' => [['value' => 'demandee', 'label' => 'Demandée'], ['value' => 'approuvee', 'label' => 'Approuvée'], ['value' => 'refusee', 'label' => 'Refusée']]],
            'kpis' => ['pending' => $meta['counts']['demandee'], 'approved' => $meta['counts']['approuvee'], 'refused' => $meta['counts']['refusee']],
            'can' => $this->can($request),
            'header' => $this->header('Corrections de pointage', '', [['Pointages', '/pointages'], ['Corrections']]),
        ]);
    }

    public function requestCorrection(Request $request, string $attendance): RedirectResponse
    {
        $hours = trim((string) $request->input('hours'));
        $minutes = $hours === '' ? null : (int) round(((float) str_replace(',', '.', $hours)) * 60);

        return $this->send("attendance/{$attendance}/corrections", ['worked_minutes' => $minutes, 'status' => $request->input('status') ?: null, 'reason' => trim((string) $request->input('reason')) ?: null]);
    }

    public function approveCorrection(string $correction): RedirectResponse
    {
        return $this->send("attendance/corrections/{$correction}/approve");
    }

    public function refuseCorrection(string $correction): RedirectResponse
    {
        return $this->send("attendance/corrections/{$correction}/refuse");
    }

    public function remind(string $attendance): RedirectResponse
    {
        return $this->send("attendance/{$attendance}/remind");
    }

    public function reportAnomaly(Request $request, string $attendance): RedirectResponse
    {
        return $this->send("attendance/{$attendance}/anomalies", ['type' => $request->input('type') ?: null, 'comment' => trim((string) $request->input('comment')) ?: null]);
    }

    public function startAnomaly(string $anomaly): RedirectResponse
    {
        return $this->send("attendance/anomalies/{$anomaly}/start");
    }

    public function closeAnomaly(Request $request, string $anomaly): RedirectResponse
    {
        return $this->send("attendance/anomalies/{$anomaly}/close", ['outcome' => $request->input('outcome') ?: null, 'resolution' => trim((string) $request->input('resolution')) ?: null]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $uri, array $data = []): RedirectResponse
    {
        try {
            $response = $this->api->post($uri, $data);
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['attendance', 'anomaly', 'api']));

            if ($refusal === []) {
                throw $e;
            }

            Inertia::flash('toastError', collect($refusal)->flatten()->first());

            return back();
        }

        Inertia::flash('toast', $response->json('message'));

        return back();
    }

    /**
     * Anciennes adresses du module : retour à la liste.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les pointages.');

        return to_route('attendances.index');
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function row(array $a): array
    {
        $value = $a['status']['value'];

        return [
            'id' => $a['id'],
            'date' => $a['date'],
            'status' => ['value' => $value, 'label' => self::STATUS_LABELS[$value] ?? $a['status']['label'], 'tone' => self::STATUS_TONES[$value] ?? 'n'],
            'worker' => $a['worker'],
            'site' => $a['site'],
            'promoter' => $a['promoter'],
            'trade' => $a['trade'],
            'arrival' => $a['arrival'],
            'departure' => $a['departure'],
            'minutes' => $a['worked_minutes'],
            'outOfZone' => $a['out_of_zone'],
            'openAnomalies' => $a['open_anomalies'],
            'locked' => $a['is_locked'],
        ];
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array<string, mixed>
     */
    private function correction(array $x): array
    {
        $old = $x['old_values'];
        $new = $x['new_values'];
        $hours = fn (?int $m): string => $m === null ? '—' : sprintf('%d h %02d', intdiv($m, 60), $m % 60);
        $changes = [];

        if (array_key_exists('worked_minutes', $new)) {
            $changes[] = 'Durée : '.$hours($old['worked_minutes'] ?? null).' → '.$hours($new['worked_minutes']);
        }

        if (array_key_exists('status', $new)) {
            $changes[] = 'Statut : '.(self::STATUS_LABELS[$old['status'] ?? ''] ?? '—').' → '.(self::STATUS_LABELS[$new['status']] ?? $new['status']);
        }

        return [
            'id' => $x['id'],
            'status' => ['value' => $x['status']['value'], 'label' => $x['status']['label'], 'tone' => self::CORRECTION_TONES[$x['status']['value']] ?? 'n'],
            'reason' => $x['reason'],
            'changes' => implode(' · ', $changes),
            'requestedBy' => $x['requested_by'],
            'decidedBy' => $x['decided_by'],
            'date' => $x['date'],
            'dayId' => $x['work_day_id'],
            'worker' => $x['worker'],
            'site' => $x['site'],
            'isPending' => $x['is_pending'],
            'canApprove' => $x['can_approve'],
        ];
    }

    /**
     * @param  array<string, mixed>  $x
     * @return array<string, mixed>
     */
    private function anomaly(array $x): array
    {
        return [
            'id' => $x['id'],
            'type' => $x['type']['label'],
            'status' => ['value' => $x['status']['value'], 'label' => $x['status']['label'], 'tone' => self::ANOMALY_TONES[$x['status']['value']] ?? 'n'],
            'detectedBy' => $x['detected_by']['label'],
            'comment' => $x['comment'],
            'resolution' => $x['resolution'],
            'resolvedBy' => $x['resolved_by'],
            'date' => $x['date'],
            'dayId' => $x['work_day_id'],
            'worker' => $x['worker'],
            'site' => $x['site'],
            'isOpen' => $x['is_open'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $event
     * @return array<string, mixed>|null
     */
    private function proof(?array $event): ?array
    {
        return $event ? ['time' => $event['time'], 'distance' => $event['distance_m'], 'accuracy' => $event['accuracy_m'], 'outOfZone' => $event['out_of_zone'], 'author' => $event['author'], 'photo' => $event['photo_url'], 'signature' => $event['signature_url'], 'status' => $event['status']['label']] : null;
    }

    /**
     * @return array{update: bool, export: bool, validate: bool}
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('attendance.update'), 'export' => $allowed('attendance.export'), 'validate' => $allowed('attendance.validate')];
    }

    /**
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_filter($request->only(self::FILTER_KEYS), fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    private function apiQuery(array $filters): array
    {
        $query = [];

        foreach (array_intersect_key($filters, self::QUERY) as $key => $value) {
            $query[self::QUERY[$key]] = $value;
        }

        if ($sort = self::SORTS[$filters['tri'] ?? ''] ?? null) {
            $query['sort'] = $sort;
            $query['direction'] = ($filters['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        $today = today();
        [$from, $to] = match ($filters['periode'] ?? null) {
            'aujourdhui' => [$today, $today],
            'hier' => [$today->copy()->subDay(), $today->copy()->subDay()],
            '7j' => [$today->copy()->subDays(6), $today],
            '30j' => [$today->copy()->subDays(29), $today],
            default => [null, null],
        };

        return $from ? [...$query, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()] : $query;
    }

    private function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
