<?php

namespace App\Http\Controllers\Api\V1\Contractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contractor\ContractorRatingRequest;
use App\Models\Rating;
use App\Support\ApiResponse;
use App\Support\Contractor\ContractorReport;
use App\Support\Contractor\ContractorSites;
use App\Support\Promoter\SiteRatings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fin de chantier côté entrepreneur : évaluations de chaque ouvrier et de l’équipe (notes de 1 à 5, satisfaction,
 * commentaire, modifiables pendant la période prévue) et rapport de fin de chantier (consultation et PDF).
 */
class ClosureController extends Controller
{
    public function __construct(private SiteRatings $ratings, private ContractorReport $report, private ContractorSites $sites) {}

    /**
     * Carte « Évaluer l’équipe » : ouvriers à noter ou notés, note d’équipe à la fin du chantier ; meta : libellés et
     * satisfactions proposées par note.
     */
    public function ratings(Request $request, string $contractorSite): JsonResponse
    {
        $site = $this->sites->find(ProfileController::contractor($request), $contractorSite);

        return ApiResponse::success($this->ratings->summary($site, $request->user()), meta: $this->ratings->options());
    }

    public function rate(ContractorRatingRequest $request, string $contractorSite): JsonResponse
    {
        $site = $this->sites->find(ProfileController::contractor($request), $contractorSite);
        $rating = $this->ratings->rate($site, $request->user(), $request->validated());

        return ApiResponse::success([...$this->ratings->summary($site, $request->user()), 'rating' => $this->ratings->rating($rating)], 'Évaluation publiée', status: 201);
    }

    public function update(ContractorRatingRequest $request, string $contractorRating): JsonResponse
    {
        $rating = str($contractorRating)->isUuid()
            ? Rating::query()->whereKey($contractorRating)->where('author_user_id', $request->user()->getKey())->first()
            : null;

        abort_unless($rating, 404, 'Évaluation introuvable.');

        return ApiResponse::success($this->ratings->rating($this->ratings->update($rating, $request->validated())), 'Évaluation modifiée');
    }

    public function report(Request $request, string $contractorSite): JsonResponse
    {
        $contractor = ProfileController::contractor($request);

        return ApiResponse::success($this->report->report($contractor, $this->sites->find($contractor, $contractorSite), $request->user()));
    }

    public function pdf(Request $request, string $contractorSite): Response
    {
        $contractor = ProfileController::contractor($request);
        $site = $this->sites->find($contractor, $contractorSite);

        return response($this->report->pdf($contractor, $site, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->report->filename($site).'"',
        ]);
    }
}
