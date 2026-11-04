<?php

namespace App\Http\Controllers\Api\V1\Admin\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Requests\V1\Admin\Worker\WorkerBulkApprovalRequest;
use App\Http\Requests\V1\Admin\Worker\WorkerCorrectionsRequest;
use App\Http\Requests\V1\Admin\Worker\WorkerSuspensionRequest;
use App\Http\Resources\V1\Admin\WorkerDetailResource;
use App\Models\Document;
use App\Models\Worker;
use App\Support\Admin\WorkerVerification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décisions du Back Office sur un ouvrier : pièces, dossier (validation, corrections, refus, validation groupée),
 * suspension, réactivation et exclusion. Chaque réponse renvoie la fiche à jour.
 */
class WorkerReviewController extends Controller
{
    public function __construct(private WorkerVerification $verification) {}

    public function approve(Request $request, string $worker): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->verification->approve($model, $request->user(), $request);

        return $this->fiche($request, $worker, 'Dossier validé : l’ouvrier peut être proposé sur les chantiers.');
    }

    /**
     * Valide les dossiers prêts ; les autres sont ignorés avec leur motif.
     */
    public function approveMany(WorkerBulkApprovalRequest $request): JsonResponse
    {
        $approved = [];
        $skipped = [];

        foreach (Worker::query()->whereIn('id', $request->validated('ids'))->get() as $worker) {
            if ($refusal = $this->verification->approvalRefusal($worker)) {
                $skipped[] = ['id' => $worker->id, 'full_name' => trim($worker->first_name.' '.$worker->last_name), 'reason' => $refusal];

                continue;
            }

            $this->verification->approve($worker, $request->user(), $request);
            $approved[] = $worker->id;
        }

        $count = count($approved);
        $message = $count === 0 ? 'Aucun dossier n’a pu être validé.' : $count.' dossier'.($count > 1 ? 's validés' : ' validé').'.';

        return ApiResponse::success(['approved' => $approved, 'skipped' => $skipped], $message);
    }

    public function requestCorrections(WorkerCorrectionsRequest $request, string $worker): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->verification->requestCorrections($model, $request->user(), $request->validated('corrections'), $request->validated('message'), $request);

        return $this->fiche($request, $worker, 'Corrections demandées au commercial.');
    }

    public function reject(PromoterReasonRequest $request, string $worker): JsonResponse
    {
        $this->verification->reject(WorkerController::find($worker), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $worker, 'Dossier refusé.');
    }

    public function approveDocument(Request $request, string $worker, string $document): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->verification->approveDocument($model, $this->document($model, $document), $request->user(), $request);

        return $this->fiche($request, $worker, 'Pièce vérifiée.');
    }

    public function rejectDocument(PromoterReasonRequest $request, string $worker, string $document): JsonResponse
    {
        $model = WorkerController::find($worker);
        $this->verification->rejectDocument($model, $this->document($model, $document), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $worker, 'Pièce rejetée.');
    }

    public function suspend(WorkerSuspensionRequest $request, string $worker): JsonResponse
    {
        $until = $request->until();
        $this->verification->suspend(WorkerController::find($worker), $request->user(), $request->reason(), $until, $request);

        return $this->fiche($request, $worker, $until ? 'Ouvrier suspendu jusqu’au '.$until->locale('fr')->translatedFormat('j F Y').'.' : 'Ouvrier suspendu.');
    }

    public function reactivate(Request $request, string $worker): JsonResponse
    {
        $this->verification->reactivate(WorkerController::find($worker), $request->user(), $request);

        return $this->fiche($request, $worker, 'Ouvrier réactivé.');
    }

    public function exclude(PromoterReasonRequest $request, string $worker): JsonResponse
    {
        $this->verification->exclude(WorkerController::find($worker), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $worker, 'Ouvrier exclu de la plateforme.');
    }

    private function document(Worker $worker, string $id): Document
    {
        abort_unless(str($id)->isUuid(), 404);

        return $worker->documents()->whereKey($id)->firstOrFail();
    }

    private function fiche(Request $request, string $worker, string $message): JsonResponse
    {
        return ApiResponse::success(WorkerDetailResource::make(WorkerController::find($worker))->resolve($request), $message);
    }
}
