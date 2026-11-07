<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\FinanceReport;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vue financière et journal des commissions d’une période.
 */
class FinanceController extends Controller
{
    private const AMOUNTS = ['days', 'base', 'y_amount', 'z_amount', 'earned', 'received', 'collected', 'refunded', 'remaining'];

    public function __construct(private FinanceReport $report) {}

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return ApiResponse::success($this->report->overview($from, $to));
    }

    public function commissions(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $filters = $this->filters($request);
        $rows = $this->report->commissions($from, $to, $filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (object $row) => $this->row($row))->values()->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $filters = $this->filters($request);
        $group = $filters['group'] ?? 'mission';
        $rows = $this->report->commissions($from, $to, $filters)->get()->map(fn (object $row) => $this->row($row));

        AdminAudit::record($request->user(), 'finance', 'finance.commissions_exported', $request, null, null, ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'filters' => $filters]);

        return response()->streamDownload(function () use ($rows, $group): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [...($group === 'mission' ? ['Mission', 'Chantier', 'Promoteur'] : [$group === 'site' ? 'Chantier' : 'Promoteur', 'Missions']), 'Journées', 'Base ouvriers', 'Commission Y', 'Commission Z', 'Acquise', 'Reçu', 'Encaissée', 'Remboursée', 'Reste à recevoir'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [...($group === 'mission' ? [$row['reference'], $row['site_label'], $row['promoter_name']] : [$row['label'], $row['missions']]), ...array_map(fn (string $column) => $row[$column], self::AMOUNTS)], ';');
            }

            fclose($out);
        }, 'commissions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $row): array
    {
        $values = (array) $row;

        foreach ([...self::AMOUNTS, 'missions'] as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = (int) $values[$column];
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'group' => ['nullable', Rule::in(FinanceReport::GROUPS)],
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
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
