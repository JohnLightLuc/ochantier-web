<?php

namespace App\Http\Controllers\Api\V1\Admin\Claim;

use App\Enums\ClaimCategory;
use App\Enums\ClaimStatus;
use App\Enums\Priority;
use App\Http\Controllers\Api\V1\Admin\Dispute\DisputeController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Claim\StoreClaimRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\ClaimDetailResource;
use App\Http\Resources\V1\Admin\ClaimResource;
use App\Models\Claim;
use App\Models\Site;
use App\Support\Admin\AdminAudit;
use App\Support\Admin\ClaimDirectory;
use App\Support\Admin\ClaimOperations;
use App\Support\Admin\DisputeOperations;
use App\Support\ApiResponse;
use App\Support\ClaimFiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Réclamations : liste, saisie au nom d’un demandeur, fiche et traitement.
 */
class ClaimController extends Controller
{
    public function __construct(private ClaimDirectory $directory, private ClaimOperations $operations, private ClaimFiling $filing, private DisputeOperations $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(ClaimStatus::class)],
            'category' => ['nullable', Rule::enum(ClaimCategory::class)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'claimant_type' => ['nullable', Rule::in(array_keys(ClaimDirectory::CLAIMANT_TYPES))],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->directory->claims($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(ClaimResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->counts(),
            'categories' => array_map(fn (ClaimCategory $category) => ['value' => $category->value, 'label' => $category->label()], ClaimCategory::cases()),
        ]);
    }

    public function store(StoreClaimRequest $request): JsonResponse
    {
        $data = $request->validated();
        $claimant = $this->directory->findParty($data['claimant_type'], $request->phoneNumber());
        $site = null;

        if (! empty($data['site_reference'])) {
            $site = Site::query()->where('reference', trim($data['site_reference']))->first()
                ?? throw ValidationException::withMessages(['site_reference' => 'Aucun chantier avec cette référence.']);
        }

        $claim = DB::transaction(function () use ($claimant, $data, $site, $request): Claim {
            $claim = $this->filing->file($claimant, [...$data, 'site_id' => $site?->getKey()]);
            AdminAudit::record($request->user(), 'disputes', 'claims.created', $request, $claim, null, ['status' => $claim->status->value]);

            return $claim;
        });

        $this->operations->notifyClaimant($claim, 'Réclamation enregistrée', 'Votre réclamation '.$claim->reference.' a été enregistrée par notre équipe.');

        return $this->fiche($request, $claim->id, 'Réclamation '.$claim->reference.' enregistrée.', 201);
    }

    public function show(Request $request, string $claimId): JsonResponse
    {
        return $this->fiche($request, $claimId);
    }

    public function take(Request $request, string $claimId): JsonResponse
    {
        $this->operations->take($this->find($claimId), $request->user(), $request);

        return $this->fiche($request, $claimId, 'Réclamation prise en charge.');
    }

    public function prioritize(Request $request, string $claimId): JsonResponse
    {
        $data = $request->validate(['priority' => ['required', Rule::enum(Priority::class)]]);
        $this->operations->prioritize($this->find($claimId), Priority::from($data['priority']), $request->user(), $request);

        return $this->fiche($request, $claimId, 'Priorité mise à jour.');
    }

    public function resolve(PromoterReasonRequest $request, string $claimId): JsonResponse
    {
        $this->operations->resolve($this->find($claimId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $claimId, 'Réclamation résolue.');
    }

    public function reject(PromoterReasonRequest $request, string $claimId): JsonResponse
    {
        $this->operations->reject($this->find($claimId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $claimId, 'Réclamation rejetée.');
    }

    /**
     * Passe en litige : les paiements de la mission rattachée sont bloqués jusqu’à la décision.
     */
    public function escalate(Request $request, string $claimId): JsonResponse
    {
        $data = $request->validate(['urgency' => ['required', Rule::enum(Priority::class)], 'mission_reference' => ['nullable', 'string', 'max:25']]);
        $dispute = $this->disputes->fromClaim($this->find($claimId), DisputeController::mission($data['mission_reference'] ?? null), Priority::from($data['urgency']), $request->user(), $request);
        $this->operations->notifyClaimant($this->find($claimId), 'Réclamation transmise en litige', 'Votre réclamation est transmise en litige : '.$dispute->reference.'.');

        return $this->fiche($request, $claimId, 'Litige '.$dispute->reference.' ouvert.');
    }

    public function close(Request $request, string $claimId): JsonResponse
    {
        $this->operations->close($this->find($claimId), $request->user(), $request);

        return $this->fiche($request, $claimId, 'Réclamation close.');
    }

    private function find(string $id): Claim
    {
        return $this->directory->claims([])->with('otherParty')->findOrFail($id);
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success((new ClaimDetailResource($this->find($id)))->resolve($request), $message, $status);
    }
}
