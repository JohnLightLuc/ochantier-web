<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Support\Admin\ProCommissionLedger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registre des commissions O’CHANTIER PRO : frais de service des devis, commission des offres contractuelles et
 * frais des recherches d’effectifs, collectés ou à collecter.
 */
class ProCommissionController extends Controller
{
    public function __construct(private ProCommissionLedger $ledger) {}

    /**
     * Lignes paginées. meta : pagination et totaux des lignes filtrées.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'source' => ['nullable', Rule::in(array_keys(ProCommissionLedger::SOURCES))],
            'status' => ['nullable', Rule::in(array_keys(ProCommissionLedger::STATUSES))],
            'contractor_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $lines = $this->ledger->paginate($filters, (int) ($filters['per_page'] ?? 20), (int) ($filters['page'] ?? 1));

        return ApiResponse::success($lines->items(), meta: [
            'current_page' => $lines->currentPage(),
            'last_page' => $lines->lastPage(),
            'per_page' => $lines->perPage(),
            'total' => $lines->total(),
            'totals' => $this->ledger->totals($filters),
        ]);
    }
}
