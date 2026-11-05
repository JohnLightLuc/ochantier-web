<?php

namespace App\Http\Controllers\Api\V1\Admin\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Site\SiteIndexRequest;
use App\Http\Resources\V1\Admin\SiteDetailResource;
use App\Http\Resources\V1\Admin\SiteListResource;
use App\Models\Site;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\SiteDirectory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chantiers du Back Office : liste filtrée avec compteurs, export CSV et fiche.
 */
class SiteController extends Controller
{
    public function __construct(private SiteDirectory $directory) {}

    public function index(SiteIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $sites = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(SiteListResource::collection($sites->items())->resolve($request), meta: [
            'current_page' => $sites->currentPage(),
            'last_page' => $sites->lastPage(),
            'per_page' => $sites->perPage(),
            'total' => $sites->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
        ]);
    }

    /**
     * Export CSV (séparateur « ; », UTF-8 avec BOM) des chantiers filtrés ou sélectionnés (ids).
     */
    public function export(SiteIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $sites = $this->directory->query($filters)->when($filters['ids'] ?? null, fn ($query, array $ids) => $query->whereIn('sites.id', $ids))->get();
        $rows = SiteListResource::collection($sites)->resolve($request);

        AdminAudit::record($request->user(), 'sites', 'sites.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Chantier', 'Promoteur', 'Commune', 'Type', 'Début', 'Fin prévue', 'Effectif prévu', 'Effectif affecté', 'Incidents ouverts', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['reference'], $row['label'], $row['promoter']['name'], $row['commune']['name'] ?? null, $row['construction_type']['label'] ?? null, $row['planned_start_date'], $row['planned_end_date'], $row['staffing']['required'], $row['staffing']['assigned'], $row['open_incidents_count'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'chantiers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        return ApiResponse::success(SiteDetailResource::make(self::find($site))->resolve($request));
    }

    public static function find(string $id): Site
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(SiteDirectory::class)->query(['include_archived' => true])
            ->where('sites.id', $id)
            ->with(['neighborhood', 'responsibleUser', 'closedByAdmin', 'needs' => fn ($query) => $query->orderBy('reference')->with('trade')])
            ->firstOrFail();
    }
}
