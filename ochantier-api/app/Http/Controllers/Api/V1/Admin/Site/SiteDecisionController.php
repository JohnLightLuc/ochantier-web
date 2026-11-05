<?php

namespace App\Http\Controllers\Api\V1\Admin\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\SiteDetailResource;
use App\Support\Admin\SiteOperations;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décisions du Back Office sur un chantier. Chaque réponse renvoie la fiche à jour.
 */
class SiteDecisionController extends Controller
{
    public function __construct(private SiteOperations $operations) {}

    public function suspend(PromoterReasonRequest $request, string $site): JsonResponse
    {
        $this->operations->suspend(SiteController::find($site), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $site, 'Chantier suspendu : les pointages sont bloqués.');
    }

    public function resume(Request $request, string $site): JsonResponse
    {
        $this->operations->resume(SiteController::find($site), $request->user(), $request);

        return $this->fiche($request, $site, 'Chantier repris.');
    }

    public function cancel(PromoterReasonRequest $request, string $site): JsonResponse
    {
        $this->operations->cancel(SiteController::find($site), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $site, 'Chantier annulé.');
    }

    public function close(Request $request, string $site): JsonResponse
    {
        $this->operations->close(SiteController::find($site), $request->user(), $request);

        return $this->fiche($request, $site, 'Chantier clôturé : le promoteur peut noter son équipe.');
    }

    public function archive(Request $request, string $site): JsonResponse
    {
        $this->operations->archive(SiteController::find($site), $request->user(), $request);

        return $this->fiche($request, $site, 'Chantier archivé.');
    }

    private function fiche(Request $request, string $site, string $message): JsonResponse
    {
        return ApiResponse::success(SiteDetailResource::make(SiteController::find($site))->resolve($request), $message);
    }
}
