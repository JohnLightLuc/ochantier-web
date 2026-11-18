<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\AssignWorkersRequest;
use App\Http\Requests\V1\Contractor\ReplaceWorkerRequest;
use App\Models\Worker;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorSites;
use App\Support\Contractor\ContractorTeam;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ouvriers et équipe de l’entrepreneur : catalogue, fiche d’un ouvrier, équipe d’un chantier attribué, affectation
 * directe (accord de l’ouvrier requis), membre, remplacement et annulation.
 */
class TeamController extends Controller
{
    public function __construct(private ContractorTeam $team, private ContractorSites $sites) {}

    /**
     * « Choisir des ouvriers » : filtres trade_id, city_id, search, max_rate, available ; site_id signale les
     * ouvriers déjà dans l’équipe de ce chantier.
     */
    public function catalog(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site_id' => ['nullable', 'uuid'],
            'trade_id' => ['nullable', 'uuid'],
            'city_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'max_rate' => ['nullable', 'integer', 'min:1'],
            'available' => ['nullable', 'boolean'],
        ]);
        $contractor = ProfileController::contractor($request);
        $site = isset($filters['site_id']) ? $this->sites->find($contractor, $filters['site_id']) : null;
        $workers = $this->team->catalog([...$filters, 'available' => (bool) ($filters['available'] ?? false)], $site);

        return ApiResponse::success($workers->all(), meta: ['count' => $workers->count()]);
    }

    public function worker(Request $request, string $catalogWorker): JsonResponse
    {
        $siteId = $request->validate(['site_id' => ['nullable', 'uuid']])['site_id'] ?? null;
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->team->profile($catalogWorker, $siteId ? $this->sites->find($contractor, $siteId) : null));
    }

    public function index(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->team->team($contractor, $this->sites->find($contractor, $contractorSite)));
    }

    public function store(AssignWorkersRequest $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);
        $created = $this->team->assign($contractor, $site, $request->user(), CarbonImmutable::parse($request->validated('start_date')), CarbonImmutable::parse($request->validated('end_date')), $request->validated('workers'));
        $count = $created->count();

        return ApiResponse::success($this->team->team($contractor, $site), $count > 1 ? $count.' ouvriers ajoutés à l’équipe (accords en attente).' : 'Ouvrier ajouté à l’équipe (accord en attente).', status: 201);
    }

    public function show(Request $request, string $contractorSite, string $teamAssignment): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);

        return ApiResponse::success($this->team->detail($this->team->findAssignment($contractor, $site, $teamAssignment)));
    }

    public function replace(ReplaceWorkerRequest $request, string $contractorSite, string $teamAssignment): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);
        $worker = Worker::query()->find($request->validated('worker_id'));

        abort_unless($worker, 404, 'Ouvrier introuvable.');

        $replacement = $this->team->replace($this->team->findAssignment($contractor, $site, $teamAssignment), $request->user(), $request->reasonText(),
            CarbonImmutable::parse($request->validated('effective_date')), $worker, $request->validated('daily_rate'));

        return ApiResponse::success($this->team->detail($replacement), 'Remplacement enregistré. Accord du nouvel ouvrier requis.');
    }

    public function cancel(Request $request, string $contractorSite, string $teamAssignment): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);
        $this->team->cancel($this->team->findAssignment($contractor, $site, $teamAssignment));

        return ApiResponse::success($this->team->team($contractor, $site), 'Affectation annulée.');
    }
}
