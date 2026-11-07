<?php

namespace App\Http\Controllers;

use App\Support\Api\AdminApi;
use App\Support\DashboardPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord, lu dans l’API (api/v1/admin/dashboard). Un bloc absent signifie que l’administrateur n’a pas le droit
 * de lire le module d’où il vient.
 */
class DashboardController extends Controller
{
    private const ACTIVITY_RANGES = ['7j', '30j', '3m', 'custom'];

    private const FINANCE_RANGES = ['auj', '7j', '30j', '3m'];

    public function __construct(private AdminApi $api, private DashboardPresenter $presenter) {}

    public function __invoke(Request $request): Response
    {
        $activity = in_array($request->query('periode'), self::ACTIVITY_RANGES, true) ? $request->query('periode') : '7j';
        $finance = in_array($request->query('flux'), self::FINANCE_RANGES, true) ? $request->query('flux') : '7j';
        $custom = $activity === 'custom' ? array_filter(['from' => $request->query('du'), 'to' => $request->query('au')]) : [];

        $d = $this->api->get('dashboard', ['activity' => $activity, 'finance' => $finance, ...$custom])->json('data');

        return Inertia::render('Dashboard', [
            'kpis' => array_map($this->presenter->kpi(...), $d['kpis']),
            'activity' => $d['activity'] ? $this->presenter->activity($d['activity']) : null,
            'priorities' => $this->presenter->priorities($d['priorities']),
            'recentSites' => array_map($this->presenter->site(...), $d['recent_sites']),
            'siteDistribution' => $d['site_distribution'] ? $this->presenter->distribution($d['site_distribution']) : null,
            'matching' => $d['matching'] ? $this->presenter->matching($d['matching']) : null,
            'finance' => $d['finance'] ? $this->presenter->finance($d['finance']) : null,
            'feed' => array_map($this->presenter->feedEntry(...), $d['feed']),
            'updatedAt' => now()->format('H:i'),
        ]);
    }
}
