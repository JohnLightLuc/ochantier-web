<?php

namespace App\Http\Controllers\Api\V1\Admin\Worker;

use App\Enums\AssignmentStatus;
use App\Enums\WorkDayStatus;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\WorkDay;
use App\Support\Admin\WorkerRelations;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Onglets en lecture seule de la fiche ouvrier : disponibilités, affectations, pointages, gains, avis,
 * indisponibilités et remplacements, historique.
 */
class WorkerRelationController extends Controller
{
    public function __construct(private WorkerRelations $relations) {}

    public function availability(string $worker): JsonResponse
    {
        return ApiResponse::success($this->relations->availability(WorkerController::findWithoutRelations($worker)));
    }

    /**
     * Affectations. meta : pagination et nombre d’affectations par statut.
     */
    public function assignments(Request $request, string $worker): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AssignmentStatus::class)], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $model = WorkerController::findWithoutRelations($worker);
        $page = $this->relations->assignments($model, $filters['status'] ?? null, (int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(array_map(fn (Assignment $a) => $this->relations->assignment($a), $page->items()), meta: [...$this->pagination($page), 'counts' => $this->relations->assignmentCounts($model)]);
    }

    /**
     * Journées pointées. meta : pagination, journées par statut, taux de présence, minutes validées.
     */
    public function attendance(Request $request, string $worker): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(WorkDayStatus::class)], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $model = WorkerController::findWithoutRelations($worker);
        $page = $this->relations->attendance($model, $filters['status'] ?? null, (int) ($filters['per_page'] ?? 30));

        return ApiResponse::success(array_map(fn (WorkDay $day) => $this->relations->workDay($day), $page->items()), meta: [...$this->pagination($page), ...$this->relations->attendanceSummary($model)]);
    }

    public function earnings(string $worker): JsonResponse
    {
        $earnings = $this->relations->earnings(WorkerController::findWithoutRelations($worker));

        return ApiResponse::success($earnings['data'], meta: $earnings['meta']);
    }

    public function ratings(string $worker): JsonResponse
    {
        $ratings = $this->relations->ratings(WorkerController::findWithoutRelations($worker));

        return ApiResponse::success($ratings['data'], meta: $ratings['meta']);
    }

    public function replacements(string $worker): JsonResponse
    {
        return ApiResponse::success($this->relations->replacements(WorkerController::findWithoutRelations($worker)));
    }

    /**
     * Historique, filtrable par origine (admin, commercial, systeme, mission).
     */
    public function history(Request $request, string $worker): JsonResponse
    {
        $filters = $request->validate([
            'origin' => ['nullable', Rule::in(['admin', 'commercial', 'systeme', 'mission'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['origin.in' => 'Filtre inconnu.']);
        $page = $this->relations->history(WorkerController::findWithoutRelations($worker), $filters['origin'] ?? null, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 30));

        return ApiResponse::success($page->items(), meta: $this->pagination($page));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pagination(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
