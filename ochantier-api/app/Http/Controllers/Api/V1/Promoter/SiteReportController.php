<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\SiteInvoices;
use App\Support\Promoter\SiteReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Bilan du chantier : « Coût du chantier » et « Factures », puis « Rapport de fin de chantier » (JSON et PDF).
 */
class SiteReportController extends Controller
{
    public function __construct(private SiteReport $reports, private SiteInvoices $invoices) {}

    public function invoices(Site $site): JsonResponse
    {
        return ApiResponse::success($this->invoices->summary($site));
    }

    /**
     * Rapport complet quand il est disponible ; sinon seulement l’état d’accès et le message de verrouillage.
     */
    public function show(Request $request, Site $site): JsonResponse
    {
        return ApiResponse::success($this->reports->report($site, $request->user()));
    }

    /**
     * « Télécharger le rapport (PDF) » ; 403 tant que le chantier n’est pas terminé ou sans abonnement.
     */
    public function pdf(Request $request, Site $site): Response
    {
        return response($this->reports->pdf($site, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->reports->filename($site).'"',
        ]);
    }
}
