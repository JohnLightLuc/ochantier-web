<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialEarnings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Gains » : commissions du mois (confirmées, versées, estimées) et leur historique.
 */
class EarningController extends Controller
{
    public function __construct(private CommercialEarnings $earnings) {}

    /**
     * Filtres : month (AAAA-MM, mois en cours par défaut), tab (toutes, confirmees, en_attente). meta.months : mois
     * proposés dans le sélecteur.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'tab' => ['nullable', Rule::in(array_keys(CommercialEarnings::TABS))],
        ], ['month.date_format' => 'Mois invalide (AAAA-MM).', 'tab.in' => 'Onglet inconnu.']);
        $commercial = EnsureActiveCommercial::profile($request);
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('Y-m-d', $filters['month'].'-01')->startOfDay() : CarbonImmutable::today()->startOfMonth();

        return ApiResponse::success($this->earnings->month($commercial, $month, $filters['tab'] ?? 'toutes'), meta: ['months' => $this->earnings->months($commercial)]);
    }
}
