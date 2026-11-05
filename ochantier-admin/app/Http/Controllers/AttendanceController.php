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
 * jour. La validation reste celle du promoteur ; le Back Office peut le relancer.
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
                'anomalies' => array_map(fn (array $x) => ['id' => $x['id'], 'type' => $x['type']['label'], 'status' => $x['status']['label'], 'detectedBy' => $x['detected_by']['label'], 'comment' => $x['comment'], 'resolution' => $x['resolution']], $a['anomalies']),
                'corrections' => array_map(fn (array $x) => ['id' => $x['id'], 'status' => $x['status']['label'], 'reason' => $x['reason'], 'date' => $this->day($x['created_at'])], $a['corrections']),
                'canRemind' => $a['can']['remind'],
            ],
            'can' => $this->can($request),
            'header' => $this->header('Fiche du pointage', '', [['Pointages', '/pointages'], [$a['worker']['full_name']]]),
        ]);
    }

    public function remind(string $attendance): RedirectResponse
    {
        try {
            $response = $this->api->post("attendance/{$attendance}/remind");
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['attendance', 'api']));

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
     * @param  array<string, mixed>|null  $event
     * @return array<string, mixed>|null
     */
    private function proof(?array $event): ?array
    {
        return $event ? ['time' => $event['time'], 'distance' => $event['distance_m'], 'accuracy' => $event['accuracy_m'], 'outOfZone' => $event['out_of_zone'], 'author' => $event['author'], 'photo' => $event['photo_url'], 'signature' => $event['signature_url'], 'status' => $event['status']['label']] : null;
    }

    /**
     * @return array{update: bool, export: bool}
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('attendance.update'), 'export' => $allowed('attendance.export')];
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
