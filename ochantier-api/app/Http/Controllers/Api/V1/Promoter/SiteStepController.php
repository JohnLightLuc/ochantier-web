<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteStep;
use App\Support\Promoter\SiteSteps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Chronologie » du détail chantier. Chaque action renvoie la chronologie à jour.
 */
class SiteStepController extends Controller
{
    public function __construct(private SiteSteps $steps) {}

    public function index(Site $site): JsonResponse
    {
        return response()->json(['data' => $this->steps->summary($site)]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:160']], [
            'title.required' => 'Donnez un nom à l’étape.',
            'title.max' => 'Le nom de l’étape ne doit pas dépasser 160 caractères.',
        ]);
        $step = $this->steps->add($site, $request->user(), trim($data['title']));

        return response()->json(['data' => $this->steps->summary($site), 'message' => 'Étape ajoutée : '.$step->title], 201);
    }

    public function start(Site $site, SiteStep $step): JsonResponse
    {
        $this->steps->start($step);

        return response()->json(['data' => $this->steps->summary($site), 'message' => 'Étape démarrée : '.$step->title]);
    }

    public function complete(Request $request, Site $site, SiteStep $step): JsonResponse
    {
        $this->steps->complete($step, $request->user());

        return response()->json(['data' => $this->steps->summary($site), 'message' => 'Étape validée : '.$step->title]);
    }

    public function destroy(Site $site, SiteStep $step): JsonResponse
    {
        $this->steps->remove($step);

        return response()->json(['data' => $this->steps->summary($site), 'message' => 'Étape retirée']);
    }
}
