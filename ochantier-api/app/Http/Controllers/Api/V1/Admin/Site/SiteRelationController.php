<?php

namespace App\Http\Controllers\Api\V1\Admin\Site;

use App\Enums\SiteIncidentSeverity;
use App\Enums\SiteIncidentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Site;
use App\Models\SiteIncident;
use App\Support\Admin\SiteIncidents;
use App\Support\Admin\SiteRelations;
use App\Support\ApiResponse;
use App\Support\Documents\DocumentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Onglets de la fiche chantier : équipe et pointages, incidents (déclaration et traitement), documents et historique.
 */
class SiteRelationController extends Controller
{
    public function __construct(private SiteRelations $relations, private SiteIncidents $incidents) {}

    public function team(string $site): JsonResponse
    {
        return ApiResponse::success($this->relations->team(SiteController::find($site)));
    }

    public function incidents(string $site): JsonResponse
    {
        return ApiResponse::success($this->relations->incidents(SiteController::find($site)), meta: [
            'types' => array_map(fn (SiteIncidentType $type) => ['value' => $type->value, 'label' => $type->label()], SiteIncidentType::cases()),
            'severities' => array_map(fn (SiteIncidentSeverity $severity) => ['value' => $severity->value, 'label' => $severity->label()], SiteIncidentSeverity::cases()),
        ]);
    }

    public function declareIncident(Request $request, string $site): JsonResponse
    {
        $data = $request->validate([
            'incident_type' => ['required', Rule::enum(SiteIncidentType::class)],
            'severity' => ['required', Rule::enum(SiteIncidentSeverity::class)],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
        ], ['description.required' => 'Décrivez l’incident.', 'description.min' => 'La description est trop courte.', 'occurred_at.required' => 'Indiquez la date.', 'occurred_at.before_or_equal' => 'La date ne peut pas être dans le futur.']);

        $this->incidents->declare(SiteController::find($site), $data, $request->user(), $request);

        return ApiResponse::success(message: 'Incident enregistré.', status: 201);
    }

    public function startIncident(Request $request, string $site, string $incident): JsonResponse
    {
        $model = SiteController::find($site);
        $this->incidents->start($model, $this->incident($model, $incident), $request->user(), $request);

        return ApiResponse::success(message: 'Incident pris en charge.');
    }

    public function resolveIncident(Request $request, string $site, string $incident): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'min:5', 'max:2000']], ['resolution.required' => 'Décrivez la résolution.', 'resolution.min' => 'La résolution est trop courte.']);
        $model = SiteController::find($site);
        $this->incidents->resolve($model, $this->incident($model, $incident), trim($data['resolution']), $request->user(), $request);

        return ApiResponse::success(message: 'Incident résolu.');
    }

    public function documents(Request $request, string $site): JsonResponse
    {
        return ApiResponse::success(DocumentResource::collection(app(DocumentStore::class)->active(SiteController::find($site)))->resolve($request));
    }

    public function history(Request $request, string $site): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $history = $this->relations->history(SiteController::find($site), $page);

        return ApiResponse::success($history->items(), meta: ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'per_page' => $history->perPage(), 'total' => $history->total()]);
    }

    private function incident(Site $site, string $id): SiteIncident
    {
        return $site->incidents()->whereKey($id)->firstOrFail();
    }
}
