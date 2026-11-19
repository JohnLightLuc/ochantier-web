<?php

namespace App\Http\Controllers\Api\V1\Admin\Payout;

use App\Http\Controllers\Api\V1\Admin\Contractor\ContractorController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\ContractorPayout;
use App\Models\ContractorPayoutItem;
use App\Models\PaymentTransaction;
use App\Support\Admin\ContractorPayouts;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reversements aux professionnels O’CHANTIER PRO : parts nettes dues, versements préparés, validation (envoi),
 * relance et annulation. Chaque décision renvoie le versement à jour.
 */
class ContractorPayoutController extends Controller
{
    public function __construct(private ContractorPayouts $payouts) {}

    /**
     * Professionnels à payer (parts dues) et versements, des plus récents aux plus anciens.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $payouts = ContractorPayout::query()->with(['contractor', 'createdByAdmin', 'approvedByAdmin'])->withCount('items')
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->latest()->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success([
            'payable' => $this->payouts->payable()->all(),
            'payouts' => collect($payouts->items())->map(fn (ContractorPayout $payout) => $this->summary($payout, $request))->all(),
        ], meta: ['current_page' => $payouts->currentPage(), 'last_page' => $payouts->lastPage(), 'per_page' => $payouts->perPage(), 'total' => $payouts->total()]);
    }

    public function show(Request $request, string $payout): JsonResponse
    {
        return $this->fiche($request, $payout);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['contractor_id' => ['required', 'uuid']], ['contractor_id.required' => 'Choisissez le professionnel à payer.']);
        $payout = $this->payouts->prepare(ContractorController::find($data['contractor_id']), $request->user(), $request);

        return $this->fiche($request, $payout->id, 'Versement préparé : une autre personne doit le valider.', 201);
    }

    public function approve(Request $request, string $payout): JsonResponse
    {
        $model = $this->payouts->approve($this->find($payout), $request->user(), $request);

        return $this->fiche($request, $payout, $model->status->value === 'paye' ? 'Versement envoyé.' : 'Le versement a été rejeté par l’opérateur : vous pouvez le relancer.');
    }

    public function retry(Request $request, string $payout): JsonResponse
    {
        $model = $this->payouts->retry($this->find($payout), $request->user(), $request);

        return $this->fiche($request, $payout, $model->status->value === 'paye' ? 'Versement envoyé.' : 'Le versement a de nouveau été rejeté.');
    }

    public function cancel(PromoterReasonRequest $request, string $payout): JsonResponse
    {
        $this->payouts->cancel($this->find($payout), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $payout, 'Versement annulé : les parts redeviennent dues.');
    }

    private function find(string $id): ContractorPayout
    {
        abort_unless(str($id)->isUuid(), 404);

        return ContractorPayout::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ContractorPayout $payout, Request $request): array
    {
        $open = in_array($payout->status->value, ['a_payer', 'rejete'], true);

        return [
            'id' => $payout->id,
            'reference' => $payout->reference,
            'contractor' => $payout->contractor?->only(['id', 'reference', 'name']),
            'total_amount' => $payout->total_amount,
            'items_count' => (int) ($payout->items_count ?? $payout->items()->count()),
            'status' => ['value' => $payout->status->value, 'label' => $payout->status->label()],
            'prepared_by' => $payout->createdByAdmin?->fullName(),
            'approved_by' => $payout->approvedByAdmin?->fullName(),
            'created_at' => $payout->created_at?->toIso8601String(),
            'paid_at' => $payout->paid_at?->toIso8601String(),
            'cancellation_reason' => $payout->cancellation_reason,
            'actions' => [
                'approve' => $payout->status->value === 'a_payer' && $payout->created_by_admin_id !== $request->user()->getKey(),
                'retry' => $payout->status->value === 'rejete' && $payout->created_by_admin_id !== $request->user()->getKey(),
                'cancel' => $open,
            ],
        ];
    }

    private function fiche(Request $request, string $payout, ?string $message = null, int $status = 200): JsonResponse
    {
        $model = $this->find($payout)->load(['contractor', 'createdByAdmin', 'approvedByAdmin', 'paymentMethod.operator', 'items', 'paymentTransactions']);

        return ApiResponse::success([
            ...$this->summary($model, $request),
            'method' => $model->paymentMethod ? ['operator' => $model->paymentMethod->operator?->name, 'number' => $model->paymentMethod->account_number_masked] : null,
            'items' => $model->items->map(fn (ContractorPayoutItem $item) => $item->only(['source_key', 'reference', 'label', 'site_label', 'amount']))->values(),
            'transactions' => $model->paymentTransactions->sortByDesc('initiated_at')->map(fn (PaymentTransaction $tx) => [
                'reference' => $tx->reference,
                'status' => ['value' => $tx->status->value, 'label' => $tx->status->label()],
                'amount' => (int) $tx->amount,
                'failure_message' => $tx->failure_message,
                'completed_at' => $tx->completed_at?->toIso8601String(),
            ])->values(),
        ], $message, $status);
    }
}
