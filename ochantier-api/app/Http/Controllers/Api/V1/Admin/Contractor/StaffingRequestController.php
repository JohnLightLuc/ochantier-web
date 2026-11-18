<?php

namespace App\Http\Controllers\Api\V1\Admin\Contractor;

use App\Enums\StaffingRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\StaffingRequest;
use App\Support\Admin\AdminAudit;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorStaffing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recherches d’effectifs des entrepreneurs dans le Back Office : file des recherches et constitution de l’offre
 * (recherche automatique, ou ouvriers choisis après les avoir contactés).
 */
class StaffingRequestController extends Controller
{
    public function __construct(private ContractorStaffing $staffing) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::enum(StaffingRequestStatus::class)]])['status'] ?? StaffingRequestStatus::Recherche->value;
        $requests = StaffingRequest::query()->with(['site', 'contractor', 'lines.trade', 'lines.workers.worker', 'installments', 'depositTransaction.operator'])
            ->where('status', $status)->orderBy('start_date')->limit(200)->get();

        return ApiResponse::success($requests->map(fn (StaffingRequest $model) => [
            ...$this->staffing->detail($model),
            'contractor' => $model->contractor->only(['id', 'reference', 'name']),
            'site' => ['id' => $model->site_id, 'reference' => $model->site->reference, 'title' => $model->site->label],
        ])->all(), meta: ['count' => $requests->count(), 'status' => $status]);
    }

    /**
     * Constitue l’offre : workers ([{line_id, worker_id}]) après contact des ouvriers, sinon recherche automatique.
     */
    public function offer(Request $request, string $staffingRequest): JsonResponse
    {
        $data = $request->validate([
            'workers' => ['nullable', 'array', 'max:200'],
            'workers.*.line_id' => ['required', 'uuid'],
            'workers.*.worker_id' => ['required', 'uuid'],
        ]);
        $model = StaffingRequest::query()->findOrFail($staffingRequest);
        $chosen = isset($data['workers']) ? collect($data['workers'])->groupBy('line_id')->map(fn ($rows) => $rows->pluck('worker_id')->all())->all() : null;
        $result = $this->staffing->search($model, $chosen);

        AdminAudit::record($request->user(), 'missions', 'staffing.offer_built', $request, $result, ['status' => 'recherche'], ['status' => $result->status->value]);

        return ApiResponse::success($this->staffing->detail($result), $result->status === StaffingRequestStatus::Offre ? 'Offre envoyée à l’entrepreneur.' : 'Aucun ouvrier disponible pour le moment : la recherche reste ouverte.');
    }
}
