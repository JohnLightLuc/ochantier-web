<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ClaimRequest;
use App\Http\Resources\V1\ClaimResource;
use App\Models\Attendance;
use App\Models\Claim;
use App\Models\Payment;
use App\Support\ClaimFiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Réclamations de l’ouvrier (paiement non reçu, pointage contesté…).
 */
class ClaimController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ClaimResource::collection(
            Claim::query()
                ->where('claimant->role', 'Ouvrier')
                ->where('claimant->slug', $request->user()->slug)
                ->with('site')
                ->latest('date')->latest('id')
                ->paginate(20)
        );
    }

    public function store(ClaimRequest $request, ClaimFiling $filing): JsonResponse
    {
        $worker = $request->user();
        $subject = $request->filled('payment')
            ? Payment::query()->where('reference', $request->validated('payment'))->where('worker_id', $worker->id)->firstOrFail()
            : Attendance::query()->where('reference', $request->validated('attendance'))->where('worker_id', $worker->id)->firstOrFail();

        $claim = $filing->file($worker, $subject, $request->safe()->only('category', 'subject', 'description', 'priority'));

        return ClaimResource::make($claim->load('site'))->response()->setStatusCode(201);
    }
}
