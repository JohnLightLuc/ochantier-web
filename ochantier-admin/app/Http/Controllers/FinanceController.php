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
 * Finance, lue dans l’API (api/v1/admin/finance) : vue d’une période et journal des commissions.
 */
class FinanceController extends Controller
{
    private const PER_PAGE = 20;

    private const GROUPS = ['mission' => 'mission', 'chantier' => 'site', 'promoteur' => 'promoter'];

    public function __construct(private AdminApi $api) {}

    public function index(Request $request): Response
    {
        $period = $this->period($request);
        $data = $this->api->get('finance/overview', $period)->json('data');

        return Inertia::render('Finance/Overview', ['report' => $data, 'filters' => $data['period'], 'can' => $this->can($request)]);
    }

    public function commissions(Request $request): Response
    {
        $period = $this->period($request);
        $axis = $this->axis($request);
        $search = (string) $request->query('q', '');
        $response = $this->api->get('finance/commissions', array_filter([...$period, 'group' => self::GROUPS[$axis], 'search' => $search, 'page' => $request->query('page'), 'per_page' => self::PER_PAGE]));
        $meta = $response->json('meta');
        $rows = $response->json('data');
        $from = $meta['total'] ? ($meta['current_page'] - 1) * $meta['per_page'] + 1 : 0;

        return Inertia::render('Finance/Commissions', [
            'rows' => ['data' => $rows, 'currentPage' => $meta['current_page'], 'lastPage' => $meta['last_page'], 'total' => $meta['total'], 'from' => $from, 'to' => $from ? $from + count($rows) - 1 : 0],
            'filters' => [...$meta['period'], 'axe' => $axis, 'q' => $search],
            'can' => $this->can($request),
            'header' => $this->header('Journal des commissions', '', [['Finance', '/finance-reporting'], ['Journal des commissions']]),
        ]);
    }

    public function export(Request $request): HttpResponse
    {
        $response = $this->api->get('finance/commissions/export', array_filter([...$this->period($request), 'group' => self::GROUPS[$this->axis($request)], 'search' => $request->query('q')]));

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="commissions.csv"']);
    }

    /**
     * Les pages de démonstration renvoient vers les écrans branchés sur l’API.
     */
    public function legacy(): RedirectResponse
    {
        Inertia::flash('toast', 'Cette page a été remplacée : voici la vue financière.');

        return to_route('finance.index');
    }

    private function axis(Request $request): string
    {
        $axis = (string) $request->query('axe');

        return array_key_exists($axis, self::GROUPS) ? $axis : 'mission';
    }

    /**
     * @return array<string, string>
     */
    private function period(Request $request): array
    {
        return array_filter(['from' => $request->query('du'), 'to' => $request->query('au')], fn ($value) => is_string($value) && $value !== '');
    }

    /**
     * @return array<string, bool>
     */
    private function can(Request $request): array
    {
        $user = $request->user();

        return ['export' => $user instanceof AdminUser && $user->hasPermission('finance.export')];
    }
}
