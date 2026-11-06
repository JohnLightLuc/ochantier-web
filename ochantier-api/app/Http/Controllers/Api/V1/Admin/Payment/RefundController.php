<?php

namespace App\Http\Controllers\Api\V1\Admin\Payment;

use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Payout\PaymentMethodRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\RefundResource;
use App\Models\Refund;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\Refunds;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Remboursements aux promoteurs : liste, export, fiche, numéro, validation, renvoi et refus.
 */
class RefundController extends Controller
{
    public function __construct(private Refunds $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(RefundResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => [
                'statuses' => collect(RefundStatus::cases())->mapWithKeys(fn (RefundStatus $status) => [$status->value => Refund::query()->where('status', $status)->count()])->all(),
                'executed_amount' => (int) Refund::query()->where('status', RefundStatus::Execute)->sum('amount'),
                'pending_amount' => (int) Refund::query()->where('status', RefundStatus::Demande)->sum('amount'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = RefundResource::collection($this->query($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'payments', 'payments.refunds_exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Promoteur', 'Chantier', 'Mission', 'Origine', 'Montant', 'Exécuté le', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['promoter']['full_name'], $row['site']['label'], $row['mission']['reference'], $row['origin']['label'], $row['amount'], $row['executed_at'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'remboursements.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $refundId): JsonResponse
    {
        return $this->fiche($request, $refundId);
    }

    public function chooseNumber(Request $request, string $refundId): JsonResponse
    {
        $data = $request->validate(['payment_method_id' => ['required', 'uuid']]);
        $this->refunds->chooseNumber($this->find($refundId), $data['payment_method_id'], $request->user(), $request);

        return $this->fiche($request, $refundId, 'Numéro enregistré : le remboursement doit être validé.');
    }

    public function enterNumber(PaymentMethodRequest $request, string $refundId): JsonResponse
    {
        $this->refunds->enterNumber($this->find($refundId), $request->operator(), $request->phoneNumber(), $request->user(), $request);

        return $this->fiche($request, $refundId, 'Numéro enregistré : le remboursement doit être validé.');
    }

    public function approve(Request $request, string $refundId): JsonResponse
    {
        $refund = $this->refunds->approve($this->find($refundId), $request->user(), $request);

        return $this->fiche($request, $refundId, match ($refund->status) {
            RefundStatus::Execute => 'Remboursement envoyé.',
            RefundStatus::Echoue => 'L’opérateur a refusé ce remboursement.',
            default => 'Validation enregistrée : une troisième personne doit valider.',
        });
    }

    public function retry(Request $request, string $refundId): JsonResponse
    {
        $refund = $this->refunds->retry($this->find($refundId), $request->user(), $request);

        return $this->fiche($request, $refundId, $refund->status === RefundStatus::Execute ? 'Remboursement envoyé.' : 'L’opérateur a de nouveau refusé ce remboursement.');
    }

    public function refuse(PromoterReasonRequest $request, string $refundId): JsonResponse
    {
        $this->refunds->refuse($this->find($refundId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $refundId, 'Remboursement refusé.');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'mission_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Refund>
     */
    private function query(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return Refund::query()
            ->with(['mission.site.promoterProfile.user', 'originalTransaction', 'paymentMethod.operator', 'requestedByAdmin', 'approvedByAdmin', 'secondApproverAdmin'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['mission_id'] ?? null, fn (Builder $query, string $id) => $query->where('mission_id', $id))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhereHas('mission', fn (Builder $query) => $query->where('reference', 'ilike', $search)
                    ->orWhereHas('site', fn (Builder $query) => $query->where('label', 'ilike', $search)
                        ->orWhereHas('promoterProfile.user', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search]))))))
            ->latest()
            ->orderBy('id');
    }

    private function find(string $id): Refund
    {
        return Refund::query()
            ->with(['mission.site.promoterProfile.user', 'originalTransaction', 'paymentMethod.operator', 'requestedByAdmin', 'approvedByAdmin', 'secondApproverAdmin', 'paymentTransactions.operator'])
            ->findOrFail($id);
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success((new RefundResource($this->find($id)))->resolve($request), $message, $status);
    }
}
