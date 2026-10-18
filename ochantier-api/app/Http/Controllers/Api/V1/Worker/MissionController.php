<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Enums\NeedAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AssignmentResource;
use App\Models\NeedAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Missions acceptées par l’ouvrier (en cours, à venir et passées).
 */
class MissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AssignmentResource::collection(
            NeedAssignment::query()
                ->where('worker_id', $request->user()->id)
                ->where('status', NeedAssignmentStatus::Validated)
                ->with('offer.need.site', 'offer.line')
                ->latest('selected_on')
                ->paginate(20)
        );
    }

    public function show(Request $request, NeedAssignment $assignment): AssignmentResource
    {
        abort_unless($assignment->worker_id === $request->user()->id && $assignment->status === NeedAssignmentStatus::Validated, 404);

        return AssignmentResource::make($assignment->load('offer.need.site', 'offer.line'));
    }
}
