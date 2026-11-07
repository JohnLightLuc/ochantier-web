<?php

namespace App\Http\Controllers\Api\V1\Admin\Report;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\ReportCatalog;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapports : vue d’ensemble, consultation et export CSV.
 */
class ReportController extends Controller
{
    public function __construct(private ReportCatalog $catalog) {}

    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->catalog->overview($request->user()));
    }

    public function show(Request $request, string $kind): JsonResponse
    {
        abort_unless($this->catalog->allowed($kind, $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        [$from, $to] = $this->period($request);

        return ApiResponse::success($this->catalog->build($kind, $from, $to));
    }

    public function export(Request $request, string $kind): StreamedResponse
    {
        abort_unless($this->catalog->allowed($kind, $request->user()), 403, 'Ce rapport demande le droit de lire Finance.');
        [$from, $to] = $this->period($request);
        $report = $this->catalog->build($kind, $from, $to);

        AdminAudit::record($request->user(), 'reports', 'reports.exported', $request, null, null, ['kind' => $kind, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'format' => 'csv']);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($report['columns'], 'label'), ';');

            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn (array $column) => $row[$column['key']] ?? '', $report['columns']), ';');
            }

            fclose($out);
        }, "rapport-{$kind}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Période demandée, ou le mois en cours.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']], ['to.after_or_equal' => 'La fin de période précède le début.']);

        return [
            isset($data['from']) ? CarbonImmutable::parse($data['from']) : CarbonImmutable::today()->startOfMonth(),
            isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today(),
        ];
    }
}
