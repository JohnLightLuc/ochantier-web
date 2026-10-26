<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil › « Mes objectifs » et « Mon classement ».
 */
class PerformanceController extends Controller
{
    public function __construct(private CommercialPerformance $performance) {}

    /**
     * Objectif du mois (null s’il n’est pas fixé) et objectifs des derniers mois.
     */
    public function objectives(Request $request): JsonResponse
    {
        $commercial = EnsureActiveCommercial::profile($request);

        return ApiResponse::success([
            'current' => $this->performance->objective($commercial),
            'history' => $this->performance->objectiveHistory($commercial),
            'note' => 'Objectif mensuel fixé par O’CHANTIER.',
        ]);
    }

    public function ranking(Request $request): JsonResponse
    {
        return ApiResponse::success($this->performance->ranking(EnsureActiveCommercial::profile($request)));
    }
}
