<?php

namespace App\Http\Controllers\Api\V1\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveCommercial;
use App\Support\ApiResponse;
use App\Support\Commercial\CommercialWorkers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Ouvriers » : dossiers transmis au Back office. Le dossier d’un ouvrier s’ouvre et se corrige par son prospect
 * (prospect_id).
 */
class WorkerController extends Controller
{
    public function __construct(private CommercialWorkers $workers) {}

    /**
     * Filtres : filter (tous, attente, corriger, valide), search (nom ou métier). meta : total et compteurs par onglet.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(array_keys(CommercialWorkers::TABS))],
            'search' => ['nullable', 'string', 'max:80'],
        ], ['filter.in' => 'Filtre inconnu.']);
        $list = $this->workers->list(EnsureActiveCommercial::profile($request), $filters['filter'] ?? 'tous', $filters['search'] ?? null);

        return ApiResponse::success($list['data'], meta: $list['meta']);
    }
}
