<?php

namespace App\Http\Controllers\Api\V1\Admin\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterBulkApprovalRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterCorrectionsRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterMessageRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\PromoterDetailResource;
use App\Models\Document;
use App\Models\PromoterProfile;
use App\Support\Admin\PromoterVerification;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Décisions du Back Office sur un promoteur : validation (seule ou groupée), corrections, refus, contrôle des
 * pièces, suspension, réactivation et message. Chaque décision renvoie la fiche à jour.
 */
class PromoterReviewController extends Controller
{
    public function __construct(private PromoterVerification $verification) {}

    public function approve(Request $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->approve($profile, $request->user(), $request);

        return $this->fiche($request, $profile, 'Compte promoteur validé.');
    }

    /**
     * Valide les dossiers prêts ; les autres sont ignorés avec leur motif.
     */
    public function approveMany(PromoterBulkApprovalRequest $request): JsonResponse
    {
        $approved = [];
        $skipped = [];

        foreach (PromoterProfile::query()->whereIn('id', $request->validated('ids'))->with('user')->get() as $profile) {
            if ($refusal = $this->verification->approvalRefusal($profile)) {
                $skipped[] = ['id' => $profile->id, 'full_name' => trim($profile->user->first_name.' '.$profile->user->last_name), 'reason' => $refusal];

                continue;
            }

            $this->verification->approve($profile, $request->user(), $request);
            $approved[] = $profile->id;
        }

        $count = count($approved);
        $message = $count === 0 ? 'Aucun dossier n’a pu être validé.' : $count.' compte'.($count > 1 ? 's validés' : ' validé').'.';

        return ApiResponse::success(['approved' => $approved, 'skipped' => $skipped], $message);
    }

    public function requestCorrections(PromoterCorrectionsRequest $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->requestCorrections($profile, $request->user(), $request->corrections(), $request->validated('message'), $request);

        return $this->fiche($request, $profile, 'Corrections demandées au promoteur.');
    }

    public function reject(PromoterReasonRequest $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->reject($profile, $request->user(), $request->reason(), $request);

        return $this->fiche($request, $profile, 'Dossier refusé.');
    }

    public function approveDocument(Request $request, string $promoter, string $document): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->approveDocument($profile, $this->document($profile, $document), $request->user(), $request);

        return $this->fiche($request, $profile, 'Pièce vérifiée.');
    }

    public function rejectDocument(PromoterReasonRequest $request, string $promoter, string $document): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->rejectDocument($profile, $this->document($profile, $document), $request->user(), $request->reason(), $request);

        return $this->fiche($request, $profile, 'Pièce rejetée.');
    }

    public function suspend(PromoterReasonRequest $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->suspend($profile, $request->user(), $request->reason(), $request);

        return $this->fiche($request, $profile, 'Compte suspendu.');
    }

    public function reactivate(Request $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->reactivate($profile, $request->user(), $request);

        return $this->fiche($request, $profile, 'Compte réactivé.');
    }

    public function message(PromoterMessageRequest $request, string $promoter): JsonResponse
    {
        $profile = PromoterController::find($promoter);
        $this->verification->message($profile, $request->user(), $request->validated('title'), $request->validated('body'), $request);

        return ApiResponse::success(null, 'Message envoyé au promoteur.');
    }

    private function document(PromoterProfile $profile, string $id): Document
    {
        abort_unless(str($id)->isUuid(), 404);

        return app(PromoterAccount::class)->findDocument($profile, $id) ?? abort(404);
    }

    private function fiche(Request $request, PromoterProfile $profile, string $message): JsonResponse
    {
        return ApiResponse::success(PromoterDetailResource::make(PromoterController::find($profile->id))->resolve($request), $message);
    }
}
