<?php

namespace App\Http\Controllers\Api\V1\Admin\Payout;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Payout\BatchIndexRequest;
use App\Http\Requests\V1\Admin\Payout\BatchStoreRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\PayoutBatchResource;
use App\Models\WorkerPayoutBatch;
use App\Support\Admin\PayoutDirectory;
use App\Support\Admin\WorkerPayouts;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lots de reversements : préparation, soumission, validation par une autre personne (qui exécute le lot), renvoi en
 * préparation, annulation et reprise d’une exécution interrompue.
 */
class PayoutBatchController extends Controller
{
    public function __construct(private PayoutDirectory $directory, private WorkerPayouts $payouts) {}

    public function index(BatchIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $rows = $this->directory->batches($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(PayoutBatchResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->batchCounts(),
        ]);
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        return ApiResponse::success((new PayoutBatchResource(self::find($batchId)))->resolve($request));
    }

    public function store(BatchStoreRequest $request): JsonResponse
    {
        $batch = $this->payouts->prepare($request->validated('items'), $request->user(), $request);

        return ApiResponse::success((new PayoutBatchResource(self::find($batch->id)))->resolve($request), 'Lot préparé.', 201);
    }

    public function submit(Request $request, string $batchId): JsonResponse
    {
        $this->payouts->submit(self::find($batchId), $request->user(), $request);

        return $this->fiche($request, $batchId, 'Lot soumis : une autre personne doit le valider.');
    }

    public function approve(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->payouts->approve(self::find($batchId), $request->user(), $request);

        return $this->fiche($request, $batchId, $this->outcome($batch));
    }

    public function resume(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->payouts->resume(self::find($batchId), $request->user(), $request);

        return $this->fiche($request, $batchId, $this->outcome($batch));
    }

    public function reject(PromoterReasonRequest $request, string $batchId): JsonResponse
    {
        $this->payouts->reject(self::find($batchId), $request->validated('reason'), $request->user(), $request);

        return $this->fiche($request, $batchId, 'Lot renvoyé en préparation.');
    }

    public function cancel(Request $request, string $batchId): JsonResponse
    {
        $this->payouts->cancel(self::find($batchId), $request->user(), $request);

        return $this->fiche($request, $batchId, 'Lot annulé : ses journées sont de nouveau à reverser.');
    }

    private function outcome(WorkerPayoutBatch $batch): string
    {
        return sprintf('Lot exécuté : %d réussi(s), %d échoué(s).', $batch->results['reussis'] ?? 0, $batch->results['echoues'] ?? 0);
    }

    private function fiche(Request $request, string $batchId, string $message): JsonResponse
    {
        return ApiResponse::success((new PayoutBatchResource(self::find($batchId)))->resolve($request), $message);
    }

    private static function find(string $id): WorkerPayoutBatch
    {
        return WorkerPayoutBatch::query()
            ->with(['createdByAdmin', 'approvedByAdmin', 'workerPayouts' => fn ($query) => $query->with(['worker', 'mission.site', 'paymentMethod.operator', 'batch', 'payoutAdjustments'])->withCount('workDays')->orderBy('reference')])
            ->findOrFail($id);
    }
}
