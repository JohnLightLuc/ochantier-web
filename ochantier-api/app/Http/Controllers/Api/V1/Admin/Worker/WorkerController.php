<?php

namespace App\Http\Controllers\Api\V1\Admin\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Worker\WorkerIndexRequest;
use App\Http\Resources\V1\Admin\WorkerDetailResource;
use App\Http\Resources\V1\Admin\WorkerListResource;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\WorkerDirectory;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ouvriers du Back Office : liste filtrée avec compteurs, export CSV et fiche.
 */
class WorkerController extends Controller
{
    public function __construct(private WorkerDirectory $directory) {}

    /**
     * Liste paginée. meta : pagination, compteurs (statuts, disponibilités, listes « à traiter »), métiers et communes
     * des filtres.
     */
    public function index(WorkerIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $workers = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(WorkerListResource::collection($workers->items())->resolve($request), meta: [
            'current_page' => $workers->currentPage(),
            'last_page' => $workers->lastPage(),
            'per_page' => $workers->perPage(),
            'total' => $workers->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
        ]);
    }

    /**
     * Export CSV (séparateur « ; », UTF-8 avec BOM) des ouvriers filtrés ou sélectionnés (ids).
     */
    public function export(WorkerIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $query = $this->directory->query($filters)->when($filters['ids'] ?? null, fn ($query, array $ids) => $query->whereIn('workers.id', $ids));

        AdminAudit::record($request->user(), 'workers', 'workers.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Référence', 'Nom', 'Téléphone', 'Métier', 'Expérience (ans)', 'Coût journalier (FCFA)', 'Note', 'Disponibilité', 'Commune', 'Commercial', 'Statut', 'Transmis le', 'Validé le'], ';');

            foreach ($query->cursor() as $worker) {
                /** @var Worker $worker */
                $primary = $worker->workerTrades->firstWhere('is_primary', true) ?? $worker->workerTrades->first();
                $commercial = $worker->currentCommercial?->user;

                fputcsv($out, [
                    $worker->reference,
                    trim($worker->first_name.' '.$worker->last_name),
                    PhoneNumber::of($worker)?->formatted(),
                    $primary instanceof WorkerTrade ? $primary->trade?->label : null,
                    $primary?->years_experience,
                    $worker->estimated_daily_rate,
                    $worker->rating_avg !== null ? str_replace('.', ',', (string) $worker->rating_avg) : null,
                    $worker->availability_status?->label(),
                    $worker->residenceCommune?->name,
                    $commercial ? trim($commercial->first_name.' '.$commercial->last_name) : null,
                    $worker->verification_status->label(),
                    $worker->submitted_at?->timezone(config('app.timezone'))->format('d/m/Y'),
                    $worker->validated_at?->timezone(config('app.timezone'))->format('d/m/Y'),
                ], ';');
            }

            fclose($out);
        }, 'ouvriers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $worker): JsonResponse
    {
        return ApiResponse::success(WorkerDetailResource::make(self::find($worker))->resolve($request));
    }

    /**
     * Ouvrier transmis au Back Office (les brouillons des commerciaux restent introuvables).
     */
    public static function find(string $id): Worker
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(WorkerDirectory::class)->query([])->where('workers.id', $id)
            ->with(['nationalityCountry', 'residenceNeighborhood', 'specialties', 'skills', 'references', 'originCommercial.user', 'validatedByAdmin'])
            ->firstOrFail();
    }
}
