<?php

namespace App\Http\Controllers\Api\V1\Admin\Access;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Journal d’audit : consultation et export, sans aucune écriture possible.
 */
class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)->paginate((int) ($filters['per_page'] ?? 30))->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (AuditLog $log) => $this->row($log))->values()->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module')->all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $this->query($filters)->limit(50000)->get()->map(fn (AuditLog $log) => $this->row($log));

        AdminAudit::record($request->user(), 'audit', 'audit.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Auteur', 'Module', 'Action', 'Objet', 'Adresse IP'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['date'], $row['actor'], $row['module'], $row['action'], $row['subject'], $row['ip']], ';');
            }

            fclose($out);
        }, 'journal-audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'module' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        $search = isset($filters['search']) ? '%'.addcslashes(trim($filters['search']), '%_\\').'%' : null;

        return AuditLog::query()
            ->with('actor')
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $query->where('module', $module))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('action', 'ilike', $search)->orWhere('subject_type', 'ilike', $search)))
            ->latest()
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditLog $log): array
    {
        $actor = $log->actor;

        return [
            'id' => $log->id,
            'date' => $log->created_at?->toIso8601String(),
            'actor' => match (true) {
                $actor instanceof Admin => $actor->fullName(),
                $actor instanceof User => trim($actor->first_name.' '.$actor->last_name) ?: 'Utilisateur',
                default => 'Système',
            },
            'module' => $log->module,
            'action' => $log->action,
            'subject' => $log->subject_type,
            'before' => $log->before_values,
            'after' => $log->after_values,
            'ip' => $log->ip_address,
        ];
    }
}
