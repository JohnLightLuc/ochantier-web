<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\Api\AdminUser;
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
        $user = $request->user();

        return Inertia::render('Analytics/Report', [
            'report' => [...$r, 'rows' => $rows->forPage($page, self::PER_PAGE)->values()->all()],
            'pagination' => ['currentPage' => $page, 'lastPage' => $last, 'total' => $rows->count(), 'from' => $rows->isEmpty() ? 0 : ($page - 1) * self::PER_PAGE + 1, 'to' => min($rows->count(), $page * self::PER_PAGE)],
            'can' => ['export' => $user instanceof AdminUser && $user->hasPermission('reports.export')],
            'header' => $this->header($r['title'], '', [['Rapports', '/rapports'], [$r['title']]]),
        ]);
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
     * @return array<string, string>
     */
    private function period(Request $request): array
    {
        return array_filter(['from' => $request->query('du'), 'to' => $request->query('au')], fn ($value) => is_string($value) && $value !== '');
    }
}
