<?php

namespace App\Http\Controllers\Api\V1\Admin\Commercial;

use App\Http\Controllers\Controller;
use App\Support\Admin\CommercialRankings;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Classement mensuel des commerciaux : consultation, export, clôture et publication.
 */
class CommercialRankingController extends Controller
{
    public function __construct(private CommercialRankings $rankings) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rankings->month($this->month($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $ranking = $this->rankings->month($this->month($request));

        return response()->streamDownload(function () use ($ranking): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Rang', 'Matricule', 'Commercial', 'Ouvriers validés'], ';');

            foreach ($ranking['rows'] as $row) {
                fputcsv($out, [$row['rank'], $row['employee_number'], $row['name'], $row['validated']], ';');
            }

            fclose($out);
        }, 'classement-commerciaux-'.$ranking['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function close(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $this->rankings->close($month, $request->user(), $request);

        return ApiResponse::success($this->rankings->month($month), 'Classement clôturé : les rangs sont figés.');
    }

    public function publish(Request $request): JsonResponse
    {
        $month = $this->month($request);
        $this->rankings->publish($month, $request->user(), $request);

        return ApiResponse::success($this->rankings->month($month), 'Classement publié : les commerciaux sont prévenus.');
    }

    private function month(Request $request): CarbonImmutable
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;

        return $month ? CarbonImmutable::createFromFormat('!Y-m', $month) : CarbonImmutable::today()->startOfMonth();
    }
}
