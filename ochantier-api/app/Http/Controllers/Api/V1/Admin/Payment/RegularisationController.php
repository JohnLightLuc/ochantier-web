<?php

namespace App\Http\Controllers\Api\V1\Admin\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\RefundResource;
use App\Models\Mission;
use App\Models\Refund;
use App\Support\Admin\MissionRegularisations;
use App\Support\Admin\Refunds;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fin de mission : missions à régulariser, fiche, calcul et remboursement manuel.
 */
class RegularisationController extends Controller
{
    public function __construct(private MissionRegularisations $regularisations, private Refunds $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', Rule::in(['a_faire', 'faite'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->regularisations->queue($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(collect($rows->items())->map(fn (Mission $mission) => $this->regularisations->row($mission))->values()->all(), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => [
                'a_faire' => $this->regularisations->queue(['state' => 'a_faire'])->count(),
                'faite' => $this->regularisations->queue(['state' => 'faite'])->count(),
            ],
        ]);
    }

    public function show(Request $request, string $missionId): JsonResponse
    {
        return $this->fiche($request, $missionId);
    }

    public function store(Request $request, string $missionId): JsonResponse
    {
        $this->regularisations->run($this->find($missionId), $request->user(), $request);

        return $this->fiche($request, $missionId, 'Mission régularisée.');
    }

    public function refund(PromoterReasonRequest $request, string $missionId): JsonResponse
    {
        $amount = $request->validate(['amount' => ['required', 'integer', 'min:1']])['amount'];
        $this->refunds->request($this->find($missionId), $amount, $request->reason(), $request->user(), $request);

        return $this->fiche($request, $missionId, 'Remboursement demandé : une autre personne doit le valider.', 201);
    }

    private function find(string $id): Mission
    {
        return Mission::query()->with(['site.promoterProfile.user', 'pricingSnapshot', 'paymentSchedules', 'refunds'])->findOrFail($id);
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        $mission = $this->find($id);
        $refunds = Refund::query()
            ->where('mission_id', $id)
            ->with(['mission.site.promoterProfile.user', 'originalTransaction', 'paymentMethod.operator', 'requestedByAdmin', 'approvedByAdmin', 'secondApproverAdmin'])
            ->latest()
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            ...$this->regularisations->row($mission),
            'refundable' => $this->refunds->isSettled($mission) ? $this->refunds->refundable($mission) : 0,
            'refunds' => RefundResource::collection($refunds)->resolve($request),
        ], $message, $status);
    }
}
