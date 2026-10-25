<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\RatingRequest;
use App\Models\Rating;
use App\Models\Site;
use App\Support\ApiResponse;
use App\Support\Promoter\SiteRatings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Évaluations : carte « Évaluer l’équipe » du chantier, feuille « Noter… » et page « Notes & avis » du profil.
 */
class RatingController extends Controller
{
    public function __construct(private SiteRatings $ratings) {}

    /**
     * « Notes & avis » : avis publiés, filtrés par scope (all, individuel, equipe) ; moyenne et répartition dans meta.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['scope' => ['nullable', Rule::in(['all', 'individuel', 'equipe'])]], ['scope.in' => 'Filtre inconnu.']);
        $overview = $this->ratings->overview($request->user(), $filters['scope'] ?? 'all');

        return ApiResponse::success($overview['data'], meta: $overview['meta']);
    }

    /**
     * Ouvriers à noter ou notés, note d’équipe à la fin du chantier ; satisfactions par note dans meta.
     */
    public function site(Request $request, Site $site): JsonResponse
    {
        return ApiResponse::success($this->ratings->summary($site, $request->user()), meta: $this->ratings->options());
    }

    public function store(RatingRequest $request, Site $site): JsonResponse
    {
        $rating = $this->ratings->rate($site, $request->user(), $request->validated());

        return ApiResponse::success($this->ratings->summary($site, $request->user()), 'Merci, votre avis est publié', 201, ['rating' => $this->ratings->rating($rating)]);
    }

    public function update(RatingRequest $request, Rating $rating): JsonResponse
    {
        return ApiResponse::success($this->ratings->rating($this->ratings->update($rating, $request->validated())), 'Votre avis est modifié');
    }
}
