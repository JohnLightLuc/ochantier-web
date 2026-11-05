<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Affectations : liste et fiche, lues dans l’API (api/v1/admin/assignments), une ligne par ouvrier affecté, et
 * recherche d’un remplaçant depuis la fiche. Le contact et la réponse de l’ouvrier passent par le Matching.
 *
 * Les paramètres d’URL de la liste restent en français et sont traduits pour l’API.
 */
class AssignmentController extends Controller
{
    private const PER_PAGE = 20;

    public const STATUS_TONES = ['confirmee' => 'b', 'active' => 'g', 'terminee' => 'n', 'remplacee' => 'p', 'annulee' => 'r'];

    public const STATUS_LABELS = ['confirmee' => 'Confirmée', 'active' => 'Active', 'terminee' => 'Terminée', 'remplacee' => 'Remplacée', 'annulee' => 'Annulée'];

    private const QUERY = ['q' => 'search', 'statut' => 'status', 'metier' => 'trade_id', 'localisation' => 'commune_id', 'rapide' => 'todo', 'chantier' => 'site_id', 'page' => 'page'];

    private const SORTS = ['ouvrier' => 'worker', 'debut' => 'start', 'fin' => 'end', 'statut' => 'status'];

    private const FILTER_KEYS = ['q', 'statut', 'metier', 'localisation', 'rapide', 'chantier', 'tri', 'sens', 'page'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $response = $this->api->get('assignments', [...$this->apiQuery($filters), 'per_page' => self::PER_PAGE]);
        $meta = $response->json('meta');
        $rows = array_map($this->row(...), $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;
        $option = fn (string $value, string $label): array => compact('value', 'label');

        return Inertia::render('Assignments/Index', [
            'assignments' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => $filters,
            'filterOptions' => [
                'statut' => array_map($option, array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
                'metier' => array_map(fn (array $t): array => $option($t['id'], $t['label']), $meta['trades']),
                'localisation' => array_map(fn (array $c): array => $option($c['id'], $c['name']), $meta['communes']),
            ],
            'kpis' => ['active' => $meta['counts']['statuses']['active'], 'confirmed' => $meta['counts']['statuses']['confirmee'], 'replacements' => $meta['counts']['todo']['remplacements'], 'finished' => $meta['counts']['statuses']['terminee']],
            'todo' => $meta['counts']['todo'],
            'can' => $this->can($request),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('assignments/export', $this->apiQuery($this->filters($request)));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => $response->header('Content-Disposition') ?: 'attachment; filename="affectations.csv"']);
    }

    public function show(Request $request, string $assignment): Response
    {
        $response = $this->api->get("assignments/{$assignment}");
        $a = $response->json('data');

        return Inertia::render('Assignments/Show', [
            'assignment' => [
                ...$this->row($a),
                'actualStart' => $this->day($a['actual_start_at']),
                'actualEnd' => $this->day($a['actual_end_at']),
                'endReason' => $a['end_reason'],
                'replaces' => $a['replaces'],
                'days' => $a['days'],
                'unavailabilities' => array_map(fn (array $u) => ['id' => $u['id'], 'status' => $u['status']['label'], 'declaredBy' => $u['declared_by']['label'], 'reason' => $u['reason'], 'comment' => $u['comment'], 'date' => $this->day($u['effective_at'])], $a['unavailabilities']),
                'replacements' => array_map(fn (array $r) => ['id' => $r['id'], 'status' => $r['status']['label'], 'replacedBy' => $r['replaced_by'], 'date' => $this->day($r['created_at'])], $a['replacements']),
                'search' => $this->search($a['search']),
                'can' => $a['can'],
            ],
            'reasons' => array_map(fn (array $r) => ['value' => $r['code'], 'label' => $r['label']], $response->json('meta.reasons')),
            'can' => $this->can($request),
            'header' => $this->header('Fiche de l’affectation', '', [['Affectations', '/affectations'], [$a['worker']['full_name']]]),
        ]);
    }

    public function declareUnavailability(Request $request, string $assignment): RedirectResponse
    {
        return $this->send("assignments/{$assignment}/unavailability", ['reason' => $request->input('reason') ?: null, 'comment' => trim((string) $request->input('comment')) ?: null]);
    }

    public function searchReplacement(string $replacement): RedirectResponse
    {
        return $this->send("assignments/replacements/{$replacement}/search");
    }

    public function sendReplacement(string $replacement): RedirectResponse
    {
        return $this->send("assignments/replacements/{$replacement}/send");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $uri, array $data = []): RedirectResponse
    {
        try {
            $response = $this->api->post($uri, $data);
        } catch (ValidationException $e) {
            $refusal = array_intersect_key($e->errors(), array_flip(['assignment', 'replacement', 'api']));

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
     * Anciennes adresses du module (par besoin ou par offre) : retour à la liste.
     */
    public function legacy(string $rest): RedirectResponse
    {
        Inertia::flash('toast', Str::startsWith($rest, ['besoins', 'offres']) ? 'Cette page a été remplacée : voici les affectations.' : 'Page introuvable : voici les affectations.');

        return to_route('assignments.index');
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
            'status' => ['value' => $value, 'label' => self::STATUS_LABELS[$value] ?? $a['status']['label'], 'tone' => self::STATUS_TONES[$value] ?? 'n'],
            'worker' => $a['worker'],
            'site' => $a['site'],
            'promoter' => $a['promoter'],
            'mission' => $a['mission'],
            'offerId' => $a['offer_id'],
            'trade' => $a['trade'],
            'role' => $a['role'],
            'lead' => $a['is_team_lead'],
            'startsOn' => $a['start_date'],
            'endsOn' => $a['end_date'],
            'promoterRate' => $a['promoter_daily_rate'],
            'netRate' => $a['worker_net_daily_rate'],
            'replacement' => $a['replacement'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $search
     * @return array<string, mixed>|null
     */
    private function search(?array $search): ?array
    {
        return $search ? [
            'id' => $search['id'],
            'refusal' => $search['refusal'],
            'canRun' => $search['can_run'],
            'canSend' => $search['can_send'],
            'promoterRefusal' => $search['promoter_refusal'],
            'ceiling' => $search['max_promoter_daily_rate'],
            'fromDate' => $search['from_date'],
            'lastRun' => $search['last_run'] ? ['at' => $this->day($search['last_run']['at']), 'by' => $search['last_run']['by'], 'excluded' => $search['last_run']['excluded']] : null,
            'suggestions' => array_map(fn (array $s) => ['id' => $s['id'], 'rank' => $s['rank'], 'score' => (int) round($s['score']), 'status' => ['value' => $s['status']['value'], 'label' => $s['status']['label'], 'tone' => MatchingController::SUGGESTION_TONES[$s['status']['value']] ?? 'n'], 'workerId' => $s['worker']['id'], 'name' => $s['worker']['full_name'], 'code' => $s['worker']['reference'], 'phone' => $s['worker']['phone'], 'commune' => $s['worker']['commune'], 'distance' => $s['distance_km'], 'rate' => $s['worker_daily_rate'], 'warnings' => $s['warnings']], $search['suggestions']),
        ] : null;
    }

    /**
     * @return array{update: bool, export: bool, contact: bool}
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['update' => $allowed('missions.update'), 'export' => $allowed('missions.export'), 'contact' => $allowed('matching.update')];
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

        return $query;
    }

    private function day(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }
}
