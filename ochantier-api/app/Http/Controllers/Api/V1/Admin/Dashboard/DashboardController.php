<?php

namespace App\Http\Controllers\Api\V1\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\Admin\DashboardOverview;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tableau de bord du Back Office et compteurs de la barre latérale.
 */
class DashboardController extends Controller
{
    public function __construct(private DashboardOverview $overview) {}

    public function show(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'activity' => ['nullable', Rule::in(['7j', '30j', '3m', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'finance' => ['nullable', Rule::in(['auj', '7j', '30j', '3m'])],
        ]);

        return ApiResponse::success($this->overview->build($request->user(), $filters));
    }

    public function badges(Request $request): JsonResponse
    {
        return ApiResponse::success(['badges' => $this->overview->badges($request->user()), 'priorities' => $this->overview->priorities($request->user())]);
    }
}
