<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\ReplacementRequest;
use App\Models\Assignment;
use App\Models\ReasonCode;
use App\Models\Replacement;
use App\Support\Promoter\SiteReplacements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Remplacer un ouvrier » depuis la section Équipe du chantier en cours : demande, remplaçants proposés et choix.
 */
class ReplacementController extends Controller
{
    public function __construct(private SiteReplacements $replacements) {}

    public function store(ReplacementRequest $request, Assignment $assignment): JsonResponse
    {
        $reason = ReasonCode::query()->where('category', SiteReplacements::REASON_CATEGORY)->where('code', $request->input('reason_code'))->firstOrFail();
        $replacement = $this->replacements->request($assignment->load(['mission', 'need', 'worker']), $request->user(), $reason, $request->input('comment'));

        return response()->json([
            'data' => $this->replacements->present($replacement),
            'message' => 'Demande envoyée : nous cherchons un remplaçant disponible.',
        ], 201);
    }

    public function show(Replacement $replacement): JsonResponse
    {
        return response()->json(['data' => $this->replacements->present($replacement)]);
    }

    /**
     * « Confirmer le remplacement » avec le remplaçant choisi (suggestion_id).
     */
    public function accept(Request $request, Replacement $replacement): JsonResponse
    {
        $data = $request->validate(['suggestion_id' => ['required', 'uuid']], ['suggestion_id.required' => 'Choisissez un remplaçant.', 'suggestion_id.uuid' => 'Choisissez un remplaçant parmi ceux proposés.']);
        $replacement = $this->replacements->accept($replacement, $request->user(), $data['suggestion_id']);
        $presented = $this->replacements->present($replacement);

        return response()->json([
            'data' => $presented,
            'message' => $presented['replacement']['name'].' remplace '.$presented['worker']['name'],
        ]);
    }

    public function refuse(Request $request, Replacement $replacement): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']], ['comment.max' => 'La précision ne doit pas dépasser 500 caractères.']);
        $replacement = $this->replacements->refuse($replacement, $request->user(), $data['comment'] ?? null);

        return response()->json([
            'data' => $this->replacements->present($replacement),
            'message' => 'Remplaçants refusés — nous cherchons d’autres profils.',
        ]);
    }
}
