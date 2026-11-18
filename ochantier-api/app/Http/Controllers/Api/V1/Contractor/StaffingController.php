<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Enums\PaymentTransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\ContractorPaymentRequest;
use App\Http\Requests\V1\Contractor\StaffingRequestRequest;
use App\Models\PaymentTransaction;
use App\Models\StaffingRequest;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorPayments;
use App\Support\Contractor\ContractorSites;
use App\Support\Contractor\ContractorStaffing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Recherche d’effectifs de l’entrepreneur sur un chantier attribué (publication, offre, acceptation et paiement de
 * l’acompte, refus, annulation) et échéances du solde à payer.
 */
class StaffingController extends Controller
{
    public function __construct(private ContractorStaffing $staffing, private ContractorSites $sites, private ContractorPayments $payments) {}

    public function index(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $list = $this->staffing->list($contractor, $this->sites->find($contractor, $contractorSite));

        return ApiResponse::success($list->all(), meta: ['count' => $list->count()]);
    }

    public function store(StaffingRequestRequest $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);
        $created = $this->staffing->create($contractor, $site, $request->user(), CarbonImmutable::parse($request->validated('start_date')), (int) $request->validated('days'), $request->validated('lines'));

        return ApiResponse::success($this->staffing->detail($created), 'Demande d’effectifs publiée : nous recherchons les ouvriers disponibles.', status: 201);
    }

    public function show(Request $request, string $contractorSite, string $staffingRequest): JsonResponse
    {
        return ApiResponse::success($this->staffing->detail($this->find($request, $contractorSite, $staffingRequest)));
    }

    public function quote(ContractorPaymentRequest $request, string $contractorSite, string $staffingRequest): JsonResponse
    {
        return ApiResponse::success($this->staffing->depositQuote($this->find($request, $contractorSite, $staffingRequest), $this->payments->operator($request->validated('operator_code'))));
    }

    /**
     * « Accepter et payer » : en-tête Idempotency-Key conseillé (une même clé renvoie le paiement déjà demandé).
     */
    public function pay(ContractorPaymentRequest $request, string $contractorSite, string $staffingRequest): JsonResponse
    {
        $model = $this->find($request, $contractorSite, $staffingRequest);
        $transaction = $this->staffing->payDeposit($model, $request->user(), $this->payments->operator($request->validated('operator_code')), $request->phoneNumber(),
            (string) ($request->header('Idempotency-Key') ?: Str::uuid()));

        return $this->paid($transaction, $this->staffing->detail($model->refresh()->load(['site', 'lines.trade', 'lines.workers.worker', 'installments', 'depositTransaction.operator'])),
            'Acompte payé : les ouvriers ont rejoint votre équipe.');
    }

    public function refuse(Request $request, string $contractorSite, string $staffingRequest): JsonResponse
    {
        return ApiResponse::success($this->staffing->detail($this->staffing->refuse($this->find($request, $contractorSite, $staffingRequest))), 'Offre refusée : les ouvriers proposés sont libérés.');
    }

    public function cancel(Request $request, string $contractorSite, string $staffingRequest): JsonResponse
    {
        return ApiResponse::success($this->staffing->detail($this->staffing->cancel($this->find($request, $contractorSite, $staffingRequest))), 'Recherche annulée.');
    }

    /**
     * « Paiements à effectuer » : échéances du solde des recherches d’effectifs, de la plus proche à la plus lointaine.
     */
    public function installments(Request $request): JsonResponse
    {
        $due = $this->staffing->dueInstallments(ProfileController::contractor($request));

        return ApiResponse::success($due->all(), meta: ['count' => $due->count(), 'total' => (int) $due->sum('amount')]);
    }

    public function installmentQuote(ContractorPaymentRequest $request, string $installment): JsonResponse
    {
        $model = $this->staffing->findInstallment(ProfileController::contractor($request), $installment);

        return ApiResponse::success($this->staffing->installmentQuote($model, $this->payments->operator($request->validated('operator_code'))));
    }

    public function payInstallment(ContractorPaymentRequest $request, string $installment): JsonResponse
    {
        $model = $this->staffing->findInstallment(ProfileController::contractor($request), $installment);
        $transaction = $this->staffing->payInstallment($model, $request->user(), $this->payments->operator($request->validated('operator_code')), $request->phoneNumber(),
            (string) ($request->header('Idempotency-Key') ?: Str::uuid()));

        return $this->paid($transaction, ['installment_id' => $model->id], 'Échéance payée.');
    }

    private function find(Request $request, string $siteId, string $id): StaffingRequest
    {
        $contractor = ProfileController::contractor($request);

        return $this->staffing->find($contractor, $this->sites->find($contractor, $siteId), $id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function paid(PaymentTransaction $transaction, array $data, string $message): JsonResponse
    {
        $ok = $transaction->status === PaymentTransactionStatus::Reussie;

        return ApiResponse::success([
            ...$data,
            'transaction' => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'status' => ['value' => $transaction->status->value, 'label' => $transaction->status->label()],
                'amount' => $transaction->amount,
                'fees_amount' => $transaction->fees_amount,
                'total' => $transaction->amount + $transaction->fees_amount,
                'failure_message' => $transaction->failure_message,
            ],
        ], $ok ? $message : 'Le paiement n’a pas abouti. Vous pouvez réessayer.', status: $ok ? 201 : 200);
    }
}
