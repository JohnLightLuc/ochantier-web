<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\PaymentOperator;
use App\Support\Admin\PaymentOperators;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Paramètres : opérateurs de paiement proposés aux payeurs (activation, frais, montants admis, ordre, message).
 */
class PaymentOperatorController extends Controller
{
    public function __construct(private PaymentOperators $operators) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->operators->all()->values()->all());
    }

    public function update(PromoterReasonRequest $request, string $operatorId): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'supports_collection' => ['sometimes', 'boolean'],
            'supports_payout' => ['sometimes', 'boolean'],
            'collection_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:20', 'decimal:0,2'],
            'min_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'max_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'notice' => ['sometimes', 'nullable', 'string', 'max:200'],
        ], [
            'collection_fee_percent.*' => 'Saisissez des frais entre 0 et 20 %, avec deux décimales au plus.',
            'min_amount.*' => 'Saisissez un montant minimal valide.',
            'max_amount.*' => 'Saisissez un montant maximal valide.',
        ]);

        $operator = $this->operators->update(PaymentOperator::query()->findOrFail($operatorId), $data, $request->reason(), $request->user(), $request);

        return ApiResponse::success($operator, 'Opérateur mis à jour.');
    }
}
