<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\FinancialClosings;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clôtures mensuelles : préparation, clôture par une autre personne, réouverture motivée et export.
 */
class ClosingController extends Controller
{
    public function __construct(private FinancialClosings $closings) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->closings->months());
    }

    public function show(string $month): JsonResponse
    {
        return ApiResponse::success($this->closings->month($month));
    }

    public function prepare(Request $request, string $month): JsonResponse
    {
        $this->closings->prepare($month, $request->user(), $request);

        return ApiResponse::success($this->closings->month($month), 'Mois préparé : une autre personne doit le clôturer.');
    }

    public function close(Request $request, string $month): JsonResponse
    {
        $this->closings->close($month, $request->user(), $request);

        return ApiResponse::success($this->closings->month($month), 'Mois clôturé : ses chiffres sont figés.');
    }

    public function reopen(PromoterReasonRequest $request, string $month): JsonResponse
    {
        $this->closings->reopen($month, $request->reason(), $request->user(), $request);

        return ApiResponse::success($this->closings->month($month), 'Mois rouvert.');
    }

    public function export(Request $request, string $month): StreamedResponse
    {
        $lines = $this->closings->lines($month);
        AdminAudit::record($request->user(), 'finance', 'finance.closing_exported', $request, null, null, ['month' => $month]);

        return response()->streamDownload(function () use ($lines): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($lines as $line) {
                fputcsv($out, $line, ';');
            }

            fclose($out);
        }, "cloture-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
