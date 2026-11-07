<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rapports, lus dans l’API (api/v1/admin/reports) : vue d’ensemble et rapports par période.
 */
class ReportController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private AdminApi $api) {}

    public function index(): Response
    {
        return Inertia::render('Analytics/Overview', ['report' => $this->api->get('reports')->json('data')]);
    }

    public function show(Request $request, string $kind): Response
    {
        $r = $this->api->get("reports/{$kind}", $this->period($request))->json('data');
        $rows = collect($r['rows']);
        $last = max(1, (int) ceil($rows->count() / self::PER_PAGE));
        $page = min($last, max(1, (int) $request->query('page', 1)));

        return Inertia::render('Analytics/Report', [
            'report' => [...$r, 'rows' => $rows->forPage($page, self::PER_PAGE)->values()->all()],
            'pagination' => ['currentPage' => $page, 'lastPage' => $last, 'total' => $rows->count(), 'from' => $rows->isEmpty() ? 0 : ($page - 1) * self::PER_PAGE + 1, 'to' => min($rows->count(), $page * self::PER_PAGE)],
            'can' => $this->can($request),
            'header' => $this->header($r['title'], '', [['Rapports', '/rapports'], [$r['title']]]),
        ]);
    }

    public function library(Request $request): Response
    {
        $response = $this->api->get('report-library', array_filter(['page' => $request->query('page'), 'per_page' => self::PER_PAGE]));
        $meta = $response->json('meta');
        $rows = array_map(fn (array $run): array => [...$run, 'generated_at' => $this->moment($run['generated_at'])], $response->json('data'));
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Analytics/Library', [
            'runs' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'kinds' => $meta['kinds'],
            'schedules' => array_map(fn (array $s): array => [...$s, 'next_run_at' => $this->moment($s['next_run_at'])], $meta['schedules']),
            'can' => $this->can($request),
            'header' => $this->header('Bibliothèque des rapports', '', [['Rapports', '/rapports'], ['Bibliothèque']]),
        ]);
    }

    public function generate(Request $request, string $kind): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("report-library/generate/{$kind}", $request->only('from', 'to', 'format')));
    }

    public function download(string $run): HttpResponse
    {
        $response = $this->api->get("report-library/runs/{$run}/download");

        return response($response->body(), 200, array_filter(['Content-Type' => $response->header('Content-Type'), 'Content-Disposition' => $response->header('Content-Disposition')]));
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $request->input('recipients')))));

        return $this->act(fn () => $this->api->post('report-library/schedules', [...$request->only('kind', 'name', 'frequency', 'format', 'first_run_on'), 'recipients' => $recipients]));
    }

    public function toggleSchedule(string $schedule): RedirectResponse
    {
        return $this->act(fn () => $this->api->post("report-library/schedules/{$schedule}/toggle"));
    }

    public function destroySchedule(string $schedule): RedirectResponse
    {
        return $this->act(fn () => $this->api->delete("report-library/schedules/{$schedule}"));
    }

    public function export(Request $request, string $kind): HttpResponse
    {
        $response = $this->api->get("reports/{$kind}/export", $this->period($request));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"rapport-{$kind}.csv\""]);
    }

    /**
     * Les pages de démonstration renvoient vers la vue d’ensemble.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici les rapports.');

        return to_route('analytics.index');
    }

    /**
     * Une erreur de champ reste sous le champ du formulaire.
     */
    private function act(callable $call): RedirectResponse
    {
        $response = $call();
        Inertia::flash('toast', $response->json('message'));

        return back();
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();
        $allowed = fn (string $permission): bool => $user instanceof AdminUser && $user->hasPermission($permission);

        return ['export' => $allowed('reports.export'), 'create' => $allowed('reports.create')];
    }

    private function moment(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->setTimezone(config('app.timezone'))->format('Y-m-d H:i') : null;
    }

    /**
     * @return array<string, string>
     */
    private function period(Request $request): array
    {
        return array_filter(['from' => $request->query('du'), 'to' => $request->query('au')], fn ($value) => is_string($value) && $value !== '');
    }
}
