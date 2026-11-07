<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Enums\ReconciliationItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Models\PaymentOperator;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationRun;
use App\Support\Admin\Reconciliations;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rapprochements : relevés importés, écarts relevés et leur traitement.
 */
class ReconciliationController extends Controller
{
    public function __construct(private Reconciliations $reconciliations) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = ReconciliationRun::query()
            ->with('runByAdmin')
            ->withCount(['reconciliationItems as open_items' => fn ($query) => $query->where('status', ReconciliationItemStatus::Ouvert)])
            ->orderByDesc('started_at')
            ->orderBy('id')
            ->paginate((int) $request->input('per_page', 20))
            ->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (ReconciliationRun $run) => $this->run($run))->values()->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->reconciliations->counts(),
            'operators' => $this->reconciliations->operators()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operator_id' => ['required', 'uuid', Rule::exists('payment_operators', 'id')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ], ['file.*' => 'Ajoutez un relevé CSV de 5 Mo maximum.', 'to.after_or_equal' => 'La fin de période précède le début.']);

        $run = $this->reconciliations->import(PaymentOperator::query()->findOrFail($data['operator_id']), CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $request->file('file'), $request->user(), $request);

        return $this->fiche($request, $run->id, $run->summary['matched'].' opération(s) rapprochée(s), '.$run->summary['discrepancies'].' écart(s) à traiter.', 201);
    }

    public function show(Request $request, string $runId): JsonResponse
    {
        return $this->fiche($request, $runId);
    }

    public function accept(PromoterReasonRequest $request, string $runId, string $itemId): JsonResponse
    {
        $this->reconciliations->decide($this->item($runId, $itemId), ReconciliationItemStatus::Accepte, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $runId, 'Écart accepté.');
    }

    public function resolve(PromoterReasonRequest $request, string $runId, string $itemId): JsonResponse
    {
        $this->reconciliations->decide($this->item($runId, $itemId), ReconciliationItemStatus::Resolu, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $runId, 'Écart résolu.');
    }

    private function item(string $runId, string $itemId): ReconciliationItem
    {
        return ReconciliationItem::query()->where('run_id', $runId)->findOrFail($itemId);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(ReconciliationRun $run): array
    {
        return [
            'id' => $run->id,
            'provider' => $run->provider,
            'period' => ['from' => $run->period_start->toDateString(), 'to' => $run->period_end->toDateString()],
            'status' => ['value' => $run->status->value, 'label' => $run->status->label()],
            'summary' => $run->summary,
            'open_items' => (int) ($run->open_items ?? 0),
            'by' => $run->runByAdmin?->fullName(),
            'imported_at' => $run->started_at?->toIso8601String(),
        ];
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        $run = ReconciliationRun::query()->with(['runByAdmin', 'reconciliationItems.transaction', 'reconciliationItems.resolvedByAdmin'])->withCount(['reconciliationItems as open_items' => fn ($query) => $query->where('status', ReconciliationItemStatus::Ouvert)])->findOrFail($id);

        return ApiResponse::success([
            ...$this->run($run),
            'items' => $run->reconciliationItems->sortBy(fn (ReconciliationItem $item) => [$item->status === ReconciliationItemStatus::Ouvert ? 0 : 1, $item->provider_reference])->map(fn (ReconciliationItem $item) => [
                'id' => $item->id,
                'type' => ['value' => $item->discrepancy_type->value, 'label' => $item->discrepancy_type->label()],
                'status' => ['value' => $item->status->value, 'label' => $item->status->label()],
                'reference' => $item->provider_reference,
                'transaction' => $item->transaction ? ['id' => $item->transaction->id, 'reference' => $item->transaction->reference, 'direction' => $item->transaction->direction->value] : null,
                'provider_amount' => $item->provider_amount,
                'platform_amount' => $item->platform_amount,
                'resolution' => $item->resolution,
                'resolved_by' => $item->resolvedByAdmin?->fullName(),
                'resolved_at' => $item->resolved_at?->toIso8601String(),
            ])->values()->all(),
        ], $message, $status);
    }
}
