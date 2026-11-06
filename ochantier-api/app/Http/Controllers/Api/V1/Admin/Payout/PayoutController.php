<?php

namespace App\Http\Controllers\Api\V1\Admin\Payout;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Payout\AdjustmentRequest;
use App\Http\Requests\V1\Admin\Payout\PayableIndexRequest;
use App\Http\Requests\V1\Admin\Payout\PayoutIndexRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\PayoutDetailResource;
use App\Http\Resources\V1\Admin\PayoutListResource;
use App\Models\PayoutAdjustment;
use App\Models\WorkerPayout;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\PayoutDirectory;
use App\Support\Admin\WorkerPayouts;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reversements aux ouvriers : ce qui est à reverser, liste, export, fiche, retrait, renvoi, retenues et bonus.
 */
class PayoutController extends Controller
{
    public function __construct(private PayoutDirectory $directory, private WorkerPayouts $payouts) {}

    public function payable(PayableIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $rows = $this->directory->payable($filters)->paginate((int) ($filters['per_page'] ?? 20));

        return ApiResponse::success(collect($rows->items())->map(fn (object $row) => $this->directory->payableRow($row))->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->payableCounts(),
            'pending_methods' => $this->directory->pendingMethodsCount(),
        ]);
    }

    public function index(PayoutIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $rows = $this->directory->payouts($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(PayoutListResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->payoutCounts(),
        ]);
    }

    public function export(PayoutIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = PayoutListResource::collection($this->directory->payouts($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'payouts', 'payouts.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Lot', 'Ouvrier', 'Chantier', 'Du', 'Au', 'Journées', 'Brut', 'Commission', 'Retenue', 'Bonus', 'Net', 'Numéro', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['batch']['reference'] ?? '', $row['worker']['full_name'], $row['site']['label'] ?? '', $row['period_start'], $row['period_end'], $row['days'], $row['gross_amount'], $row['commission_amount'], $row['deduction_amount'], $row['bonus_amount'], $row['net_amount'], $row['number'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'reversements.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $payoutId): JsonResponse
    {
        return ApiResponse::success((new PayoutDetailResource(self::find($payoutId)))->resolve($request));
    }

    public function cancel(PromoterReasonRequest $request, string $payoutId): JsonResponse
    {
        $this->payouts->cancelPayout(self::find($payoutId), $request->validated('reason'), $request->user(), $request);

        return $this->fiche($request, $payoutId, 'Reversement retiré : ses journées sont de nouveau à reverser.');
    }

    public function retry(Request $request, string $payoutId): JsonResponse
    {
        $this->payouts->retry(self::find($payoutId), $request->user(), $request);

        return $this->fiche($request, $payoutId, 'Reversement renvoyé.');
    }

    public function propose(AdjustmentRequest $request, string $payoutId): JsonResponse
    {
        $this->payouts->proposeAdjustment(self::find($payoutId), $request->validated(), $request->user(), $request);

        return $this->fiche($request, $payoutId, 'Proposition enregistrée : une autre personne doit la décider.');
    }

    public function approveAdjustment(Request $request, string $payoutId, string $adjustment): JsonResponse
    {
        $this->payouts->approveAdjustment(self::find($payoutId), $this->adjustment($payoutId, $adjustment), $request->user(), $request);

        return $this->fiche($request, $payoutId, 'Proposition approuvée : le net est recalculé.');
    }

    public function refuseAdjustment(PromoterReasonRequest $request, string $payoutId, string $adjustment): JsonResponse
    {
        $this->payouts->refuseAdjustment(self::find($payoutId), $this->adjustment($payoutId, $adjustment), $request->validated('reason'), $request->user(), $request);

        return $this->fiche($request, $payoutId, 'Proposition refusée.');
    }

    private function adjustment(string $payoutId, string $id): PayoutAdjustment
    {
        return PayoutAdjustment::query()->where('worker_payout_id', $payoutId)->findOrFail($id);
    }

    private function fiche(Request $request, string $payoutId, string $message): JsonResponse
    {
        return ApiResponse::success((new PayoutDetailResource(self::find($payoutId)))->resolve($request), $message);
    }

    private static function find(string $id): WorkerPayout
    {
        return WorkerPayout::query()
            ->with(['worker', 'mission.site', 'batch', 'paymentMethod.operator', 'paymentSchedule', 'workDays', 'payoutAdjustments.createdByAdmin', 'payoutAdjustments.approvedByAdmin', 'paymentTransactions.operator', 'createdByAdmin', 'approvedByAdmin'])
            ->withCount('workDays')
            ->findOrFail($id);
    }
}
