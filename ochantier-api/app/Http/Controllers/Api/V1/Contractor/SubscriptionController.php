<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Enums\PaymentTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\ContractorPaymentRequest;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorPayments;
use App\Support\Contractor\ContractorSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * « Abonnement et factures » de l’entrepreneur : formule en cours, souscription ou changement de formule payé par
 * Mobile Money, résiliation, réactivation et factures (PDF).
 */
class SubscriptionController extends Controller
{
    public function __construct(private ContractorSubscriptions $subscriptions, private ContractorPayments $payments) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->summary(ProfileController::contractor($request)));
    }

    public function quote(ContractorPaymentRequest $request): JsonResponse
    {
        $plan = $request->validate(['plan_code' => ['required', 'string', 'max:30']], ['plan_code.required' => 'Choisissez une formule.'])['plan_code'];

        return ApiResponse::success($this->subscriptions->quote(ProfileController::contractor($request), $plan, $this->payments->operator($request->validated('operator_code'))));
    }

    /**
     * « Souscrire » / « Passer à … » / régler la facture de renouvellement : en-tête Idempotency-Key conseillé.
     */
    public function pay(ContractorPaymentRequest $request): JsonResponse
    {
        $plan = $request->validate(['plan_code' => ['required', 'string', 'max:30']], ['plan_code.required' => 'Choisissez une formule.'])['plan_code'];
        $contractor = ProfileController::contractor($request);
        $transaction = $this->subscriptions->subscribe($contractor, $request->user(), $plan, $this->payments->operator($request->validated('operator_code')), $request->phoneNumber(),
            (string) ($request->header('Idempotency-Key') ?: Str::uuid()));
        $ok = $transaction->status === PaymentTransactionStatus::Reussie;

        return ApiResponse::success([
            ...$this->subscriptions->summary($contractor),
            'transaction' => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'status' => ['value' => $transaction->status->value, 'label' => $transaction->status->label()],
                'total' => $transaction->amount + $transaction->fees_amount,
                'failure_message' => $transaction->failure_message,
            ],
        ], $ok ? 'Abonnement activé.' : 'Le paiement n’a pas abouti. Aucun droit n’a été activé : vous pouvez réessayer.', status: $ok ? 201 : 200);
    }

    public function cancel(Request $request): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $contractor = ProfileController::contractor($request);
        $subscription = $this->subscriptions->cancel($contractor, $reason);

        return ApiResponse::success($this->subscriptions->summary($contractor), 'Abonnement résilié — actif jusqu’au '.$subscription->current_period_end->format('d/m/Y').'.');
    }

    public function reactivate(Request $request): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $this->subscriptions->reactivate($contractor);

        return ApiResponse::success($this->subscriptions->summary($contractor), 'Abonnement réactivé.');
    }

    public function invoicePdf(Request $request, string $invoice): Response
    {
        $contractor = ProfileController::contractor($request);
        $model = $this->subscriptions->findInvoice($contractor, $invoice);

        return response($this->subscriptions->invoicePdf($contractor, $model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="facture-'.$model->reference.'.pdf"',
        ]);
    }
}
