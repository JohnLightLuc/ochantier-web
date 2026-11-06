<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Support\Admin\DisputeOperations;
use App\Support\ApiResponse;
use App\Support\Promoter\PromoterDisputes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Mes litiges » : un litige s’ouvre au Back Office, souvent depuis une réclamation ; le compte le suit ici.
 */
class DisputeController extends Controller
{
    public function __construct(private PromoterDisputes $disputes, private DisputeOperations $operations) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->disputes->list(ProfileController::profile($request)));
    }

    public function show(Request $request, string $disputeId): JsonResponse
    {
        return $this->fiche($request, $disputeId);
    }

    public function message(Request $request, string $disputeId): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']], ['body.required' => 'Écrivez votre message.']);
        $this->operations->message($this->disputes->find(ProfileController::profile($request), $disputeId), trim($data['body']), false, $request->user());

        return $this->fiche($request, $disputeId, 'Message envoyé.');
    }

    public function proof(Request $request, string $disputeId): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'], 'label' => ['nullable', 'string', 'max:200']], ['file.*' => 'Ajoutez un fichier JPG, PNG ou PDF de 10 Mo maximum.']);
        $this->operations->addProof($this->disputes->find(ProfileController::profile($request), $disputeId), $request->file('file'), $request->input('label'), $request->user());

        return $this->fiche($request, $disputeId, 'Preuve ajoutée.');
    }

    public function appeal(Request $request, string $disputeId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']], ['reason.required' => 'Expliquez votre recours.', 'reason.min' => 'Votre explication est trop courte.']);
        $this->operations->appeal($this->disputes->find(ProfileController::profile($request), $disputeId), trim($data['reason']), $request->user());

        return $this->fiche($request, $disputeId, 'Votre recours est enregistré.');
    }

    private function fiche(Request $request, string $id, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->disputes->detail($this->disputes->find(ProfileController::profile($request), $id), $request->user()), $message);
    }
}
