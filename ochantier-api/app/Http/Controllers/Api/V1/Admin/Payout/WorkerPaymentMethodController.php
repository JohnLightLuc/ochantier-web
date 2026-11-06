<?php

namespace App\Http\Controllers\Api\V1\Admin\Payout;

use App\Http\Controllers\Api\V1\Admin\Worker\WorkerController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Payout\PaymentMethodRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\PaymentMethod;
use App\Models\Worker;
use App\Support\Admin\WorkerPaymentMethods;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Numéro Mobile Money d’un ouvrier : saisie par une personne, confirmation ou refus par une autre.
 */
class WorkerPaymentMethodController extends Controller
{
    public function __construct(private WorkerPaymentMethods $methods) {}

    public function pending(Request $request): JsonResponse
    {
        $rows = $this->methods->pending(min(100, max(1, (int) $request->integer('per_page', 20))));

        return ApiResponse::success(collect($rows->items())->map(fn (PaymentMethod $method) => [
            ...$this->methods->row($method, $request->user()),
            'worker' => ['id' => $method->owner_id, 'reference' => $method->owner?->reference, 'full_name' => $method->account_holder_name],
        ])->all(), meta: ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }

    public function show(Request $request, string $worker): JsonResponse
    {
        return ApiResponse::success($this->methods->overview(WorkerController::find($worker), $request->user()));
    }

    public function store(PaymentMethodRequest $request, string $worker): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->methods->propose($model, $request->operator(), $request->phoneNumber(), $request->user(), $request);

        return $this->overview($request, $model, 'Numéro enregistré : une autre personne doit le confirmer.', 201);
    }

    public function approve(Request $request, string $worker, string $method): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->methods->approve($model, PaymentMethod::query()->findOrFail($method), $request->user(), $request);

        return $this->overview($request, $model, 'Numéro confirmé : il recevra les prochains reversements.');
    }

    public function reject(PromoterReasonRequest $request, string $worker, string $method): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->methods->reject($model, PaymentMethod::query()->findOrFail($method), $request->validated('reason'), $request->user(), $request);

        return $this->overview($request, $model, 'Numéro refusé.');
    }

    private function overview(Request $request, Worker $worker, string $message, int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->methods->overview($worker, $request->user()), $message, $status);
    }
}
