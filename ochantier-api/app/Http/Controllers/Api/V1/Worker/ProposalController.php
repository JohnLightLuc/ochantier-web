<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Enums\NeedActivityCategory;
use App\Enums\NeedAssignmentStatus;
use App\Enums\WorkerActivityCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AssignmentResource;
use App\Models\Accounts\WorkerAccount;
use App\Models\NeedAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Propositions de poste reçues par l’ouvrier (Matching ou sélection manuelle) : il les accepte ou les refuse.
 */
class ProposalController extends Controller
{
    public const PENDING = [NeedAssignmentStatus::ToContact, NeedAssignmentStatus::Contacted];

    public function index(Request $request): AnonymousResourceCollection
    {
        return AssignmentResource::collection(
            NeedAssignment::query()
                ->where('worker_id', $request->user()->id)
                ->whereIn('status', self::PENDING)
                ->with('offer.need.site', 'offer.line')
                ->latest('selected_on')
                ->get()
                ->filter(fn (NeedAssignment $a): bool => $a->offer->isOpen())
                ->values()
        );
    }

    public function accept(Request $request, NeedAssignment $assignment): AssignmentResource
    {
        return $this->answer($request, $assignment, NeedAssignmentStatus::Validated);
    }

    public function refuse(Request $request, NeedAssignment $assignment): AssignmentResource
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->answer($request, $assignment, NeedAssignmentStatus::Refused, $request->string('reason'));
    }

    private function answer(Request $request, NeedAssignment $assignment, NeedAssignmentStatus $status, ?string $reason = null): AssignmentResource
    {
        /** @var WorkerAccount $worker */
        $worker = $request->user();
        abort_unless($assignment->worker_id === $worker->id, 404);
        $assignment->load('offer.need.site', 'offer.line');

        if (! in_array($assignment->status, self::PENDING, true)) {
            throw ValidationException::withMessages(['status' => 'Vous avez déjà répondu à cette proposition.']);
        }

        if (! $assignment->offer->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Cette offre n’est plus ouverte.']);
        }

        DB::transaction(function () use ($assignment, $status, $reason, $worker): void {
            $old = $assignment->status;
            $assignment->update(['status' => $status, 'reason' => $reason, 'contacted_on' => $assignment->contacted_on ?? today(), 'channel' => $assignment->channel ?? 'Application Ouvrier']);
            $action = $status === NeedAssignmentStatus::Validated ? 'a accepté le poste' : 'a refusé le poste';
            $assignment->offer->need->logActivity(NeedActivityCategory::Assignment, "{$worker->name} {$action}", $old->label(), $status->label(), $reason, $worker->origin(), $worker->name);
            $worker->logActivity(WorkerActivityCategory::Assignment, $action, $old->label(), $status->label(), "{$assignment->offer->reference} · {$assignment->offer->need->site->name}", $worker->origin(), $worker->name);
        });

        return AssignmentResource::make($assignment);
    }
}
