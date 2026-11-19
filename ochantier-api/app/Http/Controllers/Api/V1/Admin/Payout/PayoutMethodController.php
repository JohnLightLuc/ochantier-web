<?php

namespace App\Http\Controllers\Api\V1\Admin\Payout;

use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialController;
use App\Http\Controllers\Api\V1\Admin\Contractor\ContractorController;
use App\Http\Controllers\Api\V1\Admin\Worker\WorkerController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Payout\PaymentMethodRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\CommercialProfile;
use App\Models\Contractor;
use App\Models\PaymentMethod;
use App\Models\Worker;
use App\Support\Admin\PayoutMethods;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Numéro Mobile Money d’un ouvrier, d’un commercial ou d’un professionnel : saisie par une personne, confirmation ou refus par une autre.
 * Les routes indiquent le type de titulaire ; les droits diffèrent, pas le déroulement.
 */
class PayoutMethodController extends Controller
{
    public function __construct(private PayoutMethods $methods) {}

    public function pending(Request $request): JsonResponse
    {
        $rows = $this->methods->pending(min(100, max(1, (int) $request->integer('per_page', 20))));

        return ApiResponse::success(collect($rows->items())->map(fn (PaymentMethod $method) => [
            ...$this->methods->row($method, $request->user()),
            'owner' => [
                'type' => match (true) {
                    $method->owner instanceof CommercialProfile => 'commercial',
                    $method->owner instanceof Contractor => 'contractor',
                    default => 'worker',
                },
                'id' => $method->owner_id,
                'reference' => $method->owner instanceof CommercialProfile ? $method->owner->employee_number : $method->owner?->reference,
                'full_name' => $method->account_holder_name,
            ],
        ])->all(), meta: ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }

    public function show(Request $request, string $worker): JsonResponse
    {
        return ApiResponse::success($this->methods->overview(WorkerController::find($worker), $request->user()));
    }

    public function store(PaymentMethodRequest $request, string $worker): JsonResponse
    {
        return $this->propose($request, WorkerController::find($worker));
    }

    public function approve(Request $request, string $worker, string $method): JsonResponse
    {
        return $this->confirm($request, WorkerController::find($worker), $method);
    }

    public function reject(PromoterReasonRequest $request, string $worker, string $method): JsonResponse
    {
        return $this->refuse($request, WorkerController::find($worker), $method);
    }

    public function showCommercial(Request $request, string $commercial): JsonResponse
    {
        return ApiResponse::success($this->methods->overview(CommercialController::find($commercial), $request->user()));
    }

    public function storeCommercial(PaymentMethodRequest $request, string $commercial): JsonResponse
    {
        return $this->propose($request, CommercialController::find($commercial));
    }

    public function approveCommercial(Request $request, string $commercial, string $method): JsonResponse
    {
        return $this->confirm($request, CommercialController::find($commercial), $method);
    }

    public function rejectCommercial(PromoterReasonRequest $request, string $commercial, string $method): JsonResponse
    {
        return $this->refuse($request, CommercialController::find($commercial), $method);
    }

    public function showContractor(Request $request, string $contractor): JsonResponse
    {
        return ApiResponse::success($this->methods->overview(ContractorController::find($contractor), $request->user()));
    }

    public function storeContractor(PaymentMethodRequest $request, string $contractor): JsonResponse
    {
        return $this->propose($request, ContractorController::find($contractor));
    }

    public function approveContractor(Request $request, string $contractor, string $method): JsonResponse
    {
        return $this->confirm($request, ContractorController::find($contractor), $method);
    }

    public function rejectContractor(PromoterReasonRequest $request, string $contractor, string $method): JsonResponse
    {
        return $this->refuse($request, ContractorController::find($contractor), $method);
    }

    private function propose(PaymentMethodRequest $request, Worker|CommercialProfile|Contractor $owner): JsonResponse
    {
        $this->methods->propose($owner, $request->operator(), $request->phoneNumber(), $request->user(), $request);

        return $this->overview($request, $owner, 'Numéro enregistré : une autre personne doit le confirmer.', 201);
    }

    private function confirm(Request $request, Worker|CommercialProfile|Contractor $owner, string $method): JsonResponse
    {
        $this->methods->approve($owner, PaymentMethod::query()->findOrFail($method), $request->user(), $request);

        return $this->overview($request, $owner, 'Numéro confirmé : il recevra les prochains reversements.');
    }

    private function refuse(PromoterReasonRequest $request, Worker|CommercialProfile|Contractor $owner, string $method): JsonResponse
    {
        $this->methods->reject($owner, PaymentMethod::query()->findOrFail($method), $request->validated('reason'), $request->user(), $request);

        return $this->overview($request, $owner, 'Numéro refusé.');
    }

    private function overview(Request $request, Worker|CommercialProfile|Contractor $owner, string $message, int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->methods->overview($owner, $request->user()), $message, $status);
    }
}
