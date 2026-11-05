<?php

namespace App\Http\Controllers\Api\V1\Admin\Assignment;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Assignment\AssignmentIndexRequest;
use App\Http\Resources\V1\Admin\AssignmentDetailResource;
use App\Http\Resources\V1\Admin\AssignmentListResource;
use App\Models\Assignment;
use App\Models\ReasonCode;
use App\Models\Replacement;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\AssignmentDirectory;
use App\Support\ApiResponse;
use App\Support\Matching\ReplacementSearch;
use App\Support\Promoter\SiteReplacements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Affectations du Back Office : liste, export, fiche, déclaration d’une indisponibilité et recherche d’un
 * remplaçant.
 */
class AssignmentController extends Controller
{
    public function __construct(private AssignmentDirectory $directory, private SiteReplacements $replacements, private ReplacementSearch $search) {}

    public function index(AssignmentIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $assignments = $this->directory->query($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(AssignmentListResource::collection($assignments->items())->resolve($request), meta: [
            'current_page' => $assignments->currentPage(),
            'last_page' => $assignments->lastPage(),
            'per_page' => $assignments->perPage(),
            'total' => $assignments->total(),
            'counts' => $this->directory->counts(),
            ...$this->directory->options(),
            'reasons' => $this->replacements->reasons()->all(),
        ]);
    }

    public function export(AssignmentIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $rows = AssignmentListResource::collection($this->directory->query($filters)->get())->resolve($request);

        AdminAudit::record($request->user(), 'missions', 'assignments.exported', $request, null, null, ['filters' => $filters]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Ouvrier', 'Référence', 'Chantier', 'Promoteur', 'Métier', 'Début', 'Fin', 'Tarif net (FCFA)', 'Tarif promoteur (FCFA)', 'Statut'], ';');

            foreach ($rows as $row) {
                fputcsv($out, [$row['worker']['full_name'], $row['worker']['reference'], $row['site']['label'], $row['promoter']['name'], $row['trade'], $row['start_date'], $row['end_date'], $row['worker_net_daily_rate'], $row['promoter_daily_rate'], $row['status']['label']], ';');
            }

            fclose($out);
        }, 'affectations-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, string $assignment): JsonResponse
    {
        return $this->fiche($request, $assignment);
    }

    /**
     * L’ouvrier a prévenu O’CHANTIER : ouvre le remplacement comme le ferait le promoteur.
     */
    public function declareUnavailability(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::exists('reason_codes', 'code')->where('category', SiteReplacements::REASON_CATEGORY)->where('is_active', true)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], ['reason.required' => 'Choisissez le motif.']);

        $model = self::find($assignment);
        $reason = ReasonCode::query()->where('category', SiteReplacements::REASON_CATEGORY)->where('code', $data['reason'])->firstOrFail();
        $replacement = $this->replacements->request($model, $request->user(), $reason, $data['comment'] ?? null);

        AdminAudit::record($request->user(), 'missions', 'assignment.unavailability_declared', $request, $model, null, ['replacement_id' => $replacement->id, 'reason' => $reason->label]);

        return $this->fiche($request, $assignment, 'Indisponibilité déclarée : le remplacement est ouvert.');
    }

    public function searchReplacement(Request $request, string $replacement): JsonResponse
    {
        $model = Replacement::query()->findOrFail($replacement);
        $this->search->run($model, $request->user(), $request);

        return $this->fiche($request, $model->original_assignment_id, 'Recherche terminée.');
    }

    public function sendReplacement(Request $request, string $replacement): JsonResponse
    {
        $model = Replacement::query()->findOrFail($replacement);
        $this->search->send($model, $request->user(), $request);

        return $this->fiche($request, $model->original_assignment_id, 'Remplaçants envoyés au promoteur.');
    }

    private function fiche(Request $request, string $assignment, ?string $message = null): JsonResponse
    {
        return ApiResponse::success(AssignmentDetailResource::make(self::find($assignment))->resolve($request), $message, meta: ['reasons' => $this->replacements->reasons()->all()]);
    }

    public static function find(string $id): Assignment
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(AssignmentDirectory::class)->query([])->where('assignments.id', $id)->firstOrFail();
    }
}
