<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteStep;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorSites;
use App\Support\Promoter\SiteSteps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Mes chantiers » de l’entrepreneur : liste, aperçu, paiements, documents et chronologie d’un chantier attribué.
 * Les étapes de la chronologie s’ajoutent, se démarrent, se valident et se retirent comme dans l’application
 * Promoteur ; chaque action renvoie la chronologie à jour.
 */
class SiteController extends Controller
{
    public function __construct(private ContractorSites $sites, private SiteSteps $steps) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['tab' => ['nullable', Rule::in(array_keys(ContractorSites::TABS))]], ['tab.in' => 'Onglet inconnu.']);
        $list = $this->sites->list(ProfileController::contractor($request), $data['tab'] ?? 'tous');

        return ApiResponse::success($list['data'], meta: $list['meta']);
    }

    public function show(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->sites->overview($contractor, $this->sites->find($contractor, $contractorSite)));
    }

    public function payments(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->sites->payments($contractor, $this->sites->find($contractor, $contractorSite)));
    }

    public function documents(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);
        $documents = $this->sites->documentList($this->sites->find($contractor, $contractorSite));

        return ApiResponse::success($documents->all(), meta: ['count' => $documents->count(), 'notice' => 'Documents rattachés au chantier, partagés avec le promoteur selon ses droits.']);
    }

    public function timeline(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->sites->timeline($this->sites->find($contractor, $contractorSite)));
    }

    public function storeStep(Request $request, string $contractorSite): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:2', 'max:200']], ['title.required' => 'Saisissez l’intitulé de l’étape.', 'title.min' => 'Saisissez l’intitulé de l’étape.']);
        $site = $this->site($request, $contractorSite);
        $this->steps->add($site, $request->user(), trim($data['title']));

        return ApiResponse::success($this->sites->timeline($site), 'Étape ajoutée', status: 201);
    }

    public function startStep(Request $request, string $contractorSite, string $step): JsonResponse
    {
        $site = $this->site($request, $contractorSite);
        $this->steps->start($this->step($site, $step));

        return ApiResponse::success($this->sites->timeline($site), 'Étape démarrée');
    }

    public function completeStep(Request $request, string $contractorSite, string $step): JsonResponse
    {
        $site = $this->site($request, $contractorSite);
        $this->steps->complete($this->step($site, $step), $request->user());

        return ApiResponse::success($this->sites->timeline($site), 'Étape validée');
    }

    public function destroyStep(Request $request, string $contractorSite, string $step): JsonResponse
    {
        $site = $this->site($request, $contractorSite);
        $this->steps->remove($this->step($site, $step));

        return ApiResponse::success($this->sites->timeline($site), 'Étape retirée');
    }

    private function site(Request $request, string $id): Site
    {
        return $this->sites->find(ProfileController::contractor($request), $id);
    }

    private function step(Site $site, string $id): SiteStep
    {
        $step = str($id)->isUuid() ? $site->steps()->with('site')->find($id) : null;

        abort_unless($step, 404, 'Étape introuvable.');

        return $step;
    }
}
