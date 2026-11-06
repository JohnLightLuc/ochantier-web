<?php

namespace App\Http\Controllers\Api\V1\Admin\Dispute;

use App\Enums\ClaimCategory;
use App\Enums\DisputeStatus;
use App\Enums\Priority;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Dispute\DecideDisputeRequest;
use App\Http\Requests\V1\Admin\Dispute\StoreDisputeRequest;
use App\Http\Requests\V1\Admin\Promoter\PromoterReasonRequest;
use App\Http\Resources\V1\Admin\DisputeDetailResource;
use App\Http\Resources\V1\Admin\DisputeResource;
use App\Models\Dispute;
use App\Models\Mission;
use App\Support\Admin\ClaimDirectory;
use App\Support\Admin\DisputeDirectory;
use App\Support\Admin\DisputeOperations;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Litiges : liste, ouverture, fiche, instruction, décision, recours et clôture.
 */
class DisputeController extends Controller
{
    public function __construct(private DisputeDirectory $directory, private DisputeOperations $operations, private ClaimDirectory $claims) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(DisputeStatus::class)],
            'category' => ['nullable', Rule::enum(ClaimCategory::class)],
            'urgency' => ['nullable', Rule::enum(Priority::class)],
            'unassigned' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rows = $this->directory->disputes($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        return ApiResponse::success(DisputeResource::collection($rows->items())->resolve($request), meta: [
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'counts' => $this->directory->counts(),
            'categories' => array_map(fn (ClaimCategory $category) => ['value' => $category->value, 'label' => $category->label()], ClaimCategory::cases()),
        ]);
    }

    public function store(StoreDisputeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $claimant = $this->claims->findParty($data['claimant_type'], $request->phoneNumber());
        $mission = self::mission($data['mission_reference'] ?? null);
        $dispute = $this->operations->open($claimant, $data, $mission, $request->user(), $request);

        return $this->fiche($request, $dispute->id, 'Litige '.$dispute->reference.' ouvert.', 201);
    }

    public function show(Request $request, string $disputeId): JsonResponse
    {
        return $this->fiche($request, $disputeId);
    }

    public function take(Request $request, string $disputeId): JsonResponse
    {
        $this->operations->take($this->find($disputeId), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Litige pris en charge.');
    }

    public function urgency(Request $request, string $disputeId): JsonResponse
    {
        $data = $request->validate(['urgency' => ['required', Rule::enum(Priority::class)]]);
        $this->operations->setUrgency($this->find($disputeId), Priority::from($data['urgency']), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Urgence mise à jour.');
    }

    public function linkMission(Request $request, string $disputeId): JsonResponse
    {
        $data = $request->validate(['mission_reference' => ['required', 'string', 'max:25']], ['mission_reference.required' => 'Indiquez la référence de la mission.']);
        $this->operations->linkMission($this->find($disputeId), self::mission($data['mission_reference']), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Mission rattachée : ses paiements sont bloqués tant que le litige est ouvert.');
    }

    public function message(Request $request, string $disputeId): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:3000'], 'internal' => ['required', 'boolean']], ['body.required' => 'Écrivez votre message.']);
        $this->operations->message($this->find($disputeId), trim($data['body']), (bool) $data['internal'], $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Message enregistré.');
    }

    public function proof(Request $request, string $disputeId): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'], 'label' => ['nullable', 'string', 'max:200']], ['file.*' => 'Ajoutez un fichier JPG, PNG ou PDF de 10 Mo maximum.']);
        $this->operations->addProof($this->find($disputeId), $request->file('file'), $request->input('label'), $request->user());

        return $this->fiche($request, $disputeId, 'Preuve ajoutée.');
    }

    public function decide(DecideDisputeRequest $request, string $disputeId): JsonResponse
    {
        $this->operations->decide($this->find($disputeId), $request->validated(), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Décision rendue.');
    }

    public function appeal(PromoterReasonRequest $request, string $disputeId): JsonResponse
    {
        $appeal = $this->operations->appeal($this->find($disputeId), $request->reason(), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Recours '.$appeal->reference.' ouvert.', 201);
    }

    public function decideAppeal(DecideDisputeRequest $request, string $disputeId): JsonResponse
    {
        $this->operations->decideAppeal($this->find($disputeId), $request->validated(), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Décision sur le recours rendue.');
    }

    public function close(Request $request, string $disputeId): JsonResponse
    {
        $this->operations->close($this->find($disputeId), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Litige clos.');
    }

    public function requestRefund(Request $request, string $disputeId): JsonResponse
    {
        $this->operations->requestRefund($this->find($disputeId), $request->user(), $request);

        return $this->fiche($request, $disputeId, 'Demande de remboursement créée : elle attend sa validation.');
    }

    /**
     * Mission saisie par sa référence.
     */
    public static function mission(?string $reference): ?Mission
    {
        if (blank($reference)) {
            return null;
        }

        return Mission::query()->where('reference', trim($reference))->first()
            ?? throw ValidationException::withMessages(['mission_reference' => 'Aucune mission avec cette référence.']);
    }

    private function find(string $id): Dispute
    {
        return $this->directory->disputes([])->findOrFail($id);
    }

    private function fiche(Request $request, string $id, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success((new DisputeDetailResource($this->find($id)))->resolve($request), $message, $status);
    }
}
