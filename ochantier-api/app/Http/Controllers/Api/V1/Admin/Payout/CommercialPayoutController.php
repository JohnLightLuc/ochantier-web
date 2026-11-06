<?php

namespace App\Http\Controllers\Api\V1\Admin\Payout;

use App\Enums\CommercialPayoutStatus;
use App\Http\Controllers\Api\V1\Admin\Commercial\CommercialController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\CommercialPayoutResource;
use App\Models\CommercialPayout;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\CommercialPayouts;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Versements aux commerciaux : ce qui est dû à la fin d’un mois, liste, export, fiche, validation, renvoi et annulation.
 */
class CommercialPayoutController extends Controller
{
    public function __construct(private CommercialPayouts $payouts) {}

    public function payable(Request $request): JsonResponse
    {
        $month = $this->month($request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null);
        $rows = $this->payouts->payable($month);
        [$blocked, $ready] = $rows->partition(fn (array $row) => $row['blockers'] !== []);

        return ApiResponse::success($rows->values()->all(), meta: [
            'month' => $month->format('Y-m'),
            'month_finished' => $month->endOfMonth()->isPast(),
            'counts' => ['ready' => $ready->count(), 'blocked' => $blocked->count(), 'ready_amount' => (int) $ready->sum('total'), 'blocked_amount' => (int) $blocked->sum('total')],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(CommercialPayoutResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => [
                'statuses' => collect(CommercialPayoutStatus::cases())->mapWithKeys(fn (CommercialPayoutStatus $status) => [$status->value => CommercialPayout::query()->where('status', $status)->count()])->all(),
                'paid_amount' => (int) CommercialPayout::query()->where('status', CommercialPayoutStatus::Paye)->sum('total_amount'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = CommercialPayoutResource::collection($this->query($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'commercial_earnings', 'commercial.payouts_exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Commercial', 'Matricule', 'Du', 'Au', 'Montant', 'Payé le', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['commercial']['full_name'], $row['commercial']['reference'], $row['period_start'], $row['period_end'], $row['amount'], $row['paid_at'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'versements-commerciaux.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['commercial_id' => ['required', 'uuid'], 'month' => ['required', 'date_format:Y-m']]);
        $payout = $this->payouts->prepare(CommercialController::find($data['commercial_id']), $this->month($data['month']), $request->user(), $request);

        return $this->fiche($request, $payout->id, 'Versement préparé : une autre personne doit le valider.', 201);
    }

    public function show(Request $request, string $payoutId): JsonResponse
    {
        return $this->fiche($request, $payoutId);
    }

    public function approve(Request $request, string $payoutId): JsonResponse
    {
        $payout = $this->payouts->approve($this->find($payoutId), $request->user(), $request);

        return $this->fiche($request, $payoutId, $payout->status === CommercialPayoutStatus::Paye ? 'Versement envoyé.' : 'L’opérateur a refusé ce versement.');
    }

    public function retry(Request $request, string $payoutId): JsonResponse
    {
        $payout = $this->payouts->retry($this->find($payoutId), $request->user(), $request);

        return $this->fiche($request, $payoutId, $payout->status === CommercialPayoutStatus::Paye ? 'Versement envoyé.' : 'L’opérateur a de nouveau refusé ce versement.');
    }

    public function cancel(PromoterReasonRequest $request, string $payoutId): JsonResponse
    {
        $this->payouts->cancel($this->find($payoutId), $request->validated('reason'), $request->user(), $request);

        return $this->fiche($request, $payoutId, 'Versement annulé : les commissions sont de nouveau dues.');
    }

    /**
     * Par défaut, le dernier mois terminé.
     */
    private function month(?string $month): CarbonImmutable
    {
        return $month ? CarbonImmutable::createFromFormat('!Y-m', $month) : CarbonImmutable::today()->startOfMonth()->subMonth();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(CommercialPayoutStatus::class)],
            'commercial_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CommercialPayout>
     */
    private function query(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return CommercialPayout::query()
            ->with(['commercial.user', 'createdByAdmin', 'approvedByAdmin'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['commercial_id'] ?? null, fn (Builder $query, string $id) => $query->where('commercial_id', $id))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('reference', 'ilike', $search)
                ->orWhereHas('commercial', fn (Builder $query) => $query->where('employee_number', 'ilike', $search)
                    ->orWhereHas('user', fn (Builder $query) => $query->whereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$search])))))
            ->latest()
            ->orderBy('id');
    }

    private function find(string $id): CommercialPayout
    {
        return CommercialPayout::query()
            ->with(['commercial.user', 'createdByAdmin', 'approvedByAdmin', 'commercialEarnings.worker', 'commercialAdjustments', 'paymentTransactions.operator'])
            ->findOrFail($id);
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success((new CommercialPayoutResource($this->find($id)))->resolve($request), $message, $status);
    }
}
