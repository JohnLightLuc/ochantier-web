<?php

namespace App\Http\Controllers\Api\V1\Admin\Matching;

use App\Enums\ContactChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\WorkerResponseDecision;
use App\Http\Controllers\Api\V1\Admin\Offer\OfferController;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Offer\OfferIndexRequest;
use App\Http\Resources\V1\Admin\OfferListResource;
use App\Models\MatchingDerogation;
use App\Models\MatchingExclusion;
use App\Models\Suggestion;
use App\Support\Admin\OfferDirectory;
use App\Support\ApiResponse;
use App\Support\Matching\MatchingDecisions;
use App\Support\Matching\MatchingRunner;
use App\Support\Matching\WorkerConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Matching du Back Office : file des besoins à traiter, lancement du calcul et résultats par besoin.
 */
class MatchingController extends Controller
{
    public function __construct(private OfferDirectory $directory, private MatchingRunner $runner, private WorkerConsent $consent, private MatchingDecisions $decisions) {}

    /**
     * Besoins ouverts à la recherche (hors offres contractuelles), avec la date du dernier calcul.
     */
    public function queue(OfferIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $offers = $this->directory->query($filters)
            ->whereIn('offers.status', array_map(fn (OfferStatus $status) => $status->value, MatchingRunner::OPEN_OFFERS))
            ->where('offers.offer_type', '!=', OfferType::Contractuelle->value)
            ->selectSub(fn ($query) => $query->selectRaw('max(matching_runs.executed_at)')->from('matching_runs')->join('needs', 'needs.id', '=', 'matching_runs.need_id')->whereColumn('needs.offer_id', 'offers.id'), 'last_run_at')
            ->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();

        $rows = collect($offers->items())->map(fn ($offer) => [...OfferListResource::make($offer)->resolve($request), 'last_run_at' => $offer->last_run_at])->all();

        return ApiResponse::success($rows, meta: ['current_page' => $offers->currentPage(), 'last_page' => $offers->lastPage(), 'per_page' => $offers->perPage(), 'total' => $offers->total(), 'counts' => $this->directory->counts(), ...$this->directory->options()]);
    }

    public function show(Request $request, string $offer): JsonResponse
    {
        return ApiResponse::success($this->payload($request, $offer));
    }

    public function run(Request $request, string $offer): JsonResponse
    {
        $this->runner->run(OfferController::find($offer), $request->user(), $request);

        return ApiResponse::success($this->payload($request, $offer), 'Matching terminé.');
    }

    public function contact(Request $request, string $suggestion): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::enum(ContactChannel::class)]]);
        $model = $this->suggestion($suggestion);
        $this->consent->contact($model, ContactChannel::from($data['channel']), $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Contact enregistré.');
    }

    public function respond(Request $request, string $suggestion): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::enum(ContactChannel::class)],
            'decision' => ['required', Rule::enum(WorkerResponseDecision::class)],
            'conditions' => ['nullable', 'string', 'max:500'],
        ], ['decision.required' => 'Indiquez la réponse de l’ouvrier.']);
        $model = $this->suggestion($suggestion);
        $this->consent->respond($model, ContactChannel::from($data['channel']), WorkerResponseDecision::from($data['decision']), $data['conditions'] ?? null, $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Réponse enregistrée.');
    }

    public function send(Request $request, string $offer): JsonResponse
    {
        $this->consent->send(OfferController::find($offer), $request->user(), $request);

        return ApiResponse::success($this->payload($request, $offer), 'Proposition envoyée au promoteur.');
    }

    public function exclude(Request $request, string $suggestion): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], ['reason.required' => 'Indiquez le motif.', 'reason.min' => 'Le motif est trop court.']);
        $model = $this->suggestion($suggestion);
        $this->decisions->exclude($model, trim($data['reason']), $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Ouvrier écarté de ce besoin.');
    }

    public function lift(Request $request, string $exclusion): JsonResponse
    {
        $model = MatchingExclusion::query()->with('need')->findOrFail($exclusion);
        $this->decisions->lift($model, $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Écart annulé : relancez le matching.');
    }

    public function requestDerogation(Request $request, string $exclusion): JsonResponse
    {
        $data = $request->validate(['justification' => ['required', 'string', 'min:10', 'max:1000']], ['justification.required' => 'Justifiez la demande.', 'justification.min' => 'La justification est trop courte.']);
        $model = MatchingExclusion::query()->with('need')->findOrFail($exclusion);
        $this->decisions->request($model, trim($data['justification']), $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Dérogation demandée : une autre personne doit l’approuver.');
    }

    /**
     * Dérogations en attente d’approbation.
     */
    public function derogations(Request $request): JsonResponse
    {
        return ApiResponse::success(MatchingDerogation::query()->whereNull('approved_at')->whereNull('revoked_at')->with(['need.offer', 'need.trade', 'worker', 'requestedByAdmin'])->oldest()->get()->map(fn (MatchingDerogation $derogation) => [
            ...$this->runner->derogation($derogation, $request->user()),
            'offer' => ['id' => $derogation->need->offer_id, 'reference' => $derogation->need->offer->reference],
            'trade' => $derogation->need->trade?->label,
            'worker' => trim($derogation->worker->first_name.' '.$derogation->worker->last_name),
        ])->values()->all());
    }

    public function approveDerogation(Request $request, string $derogation): JsonResponse
    {
        $model = MatchingDerogation::query()->with('need')->findOrFail($derogation);
        $this->decisions->approve($model, $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Dérogation approuvée : relancez le matching.');
    }

    public function revokeDerogation(Request $request, string $derogation): JsonResponse
    {
        $model = MatchingDerogation::query()->with('need')->findOrFail($derogation);
        $this->decisions->revoke($model, $request->user(), $request);

        return ApiResponse::success($this->payload($request, $model->need->offer_id), 'Dérogation retirée.');
    }

    private function suggestion(string $id): Suggestion
    {
        return Suggestion::query()->with(['need', 'worker'])->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, string $id): array
    {
        $offer = OfferController::find($id);

        return [
            'offer' => OfferListResource::make($offer)->resolve($request),
            'run_refusal' => $this->runner->refusal($offer),
            'retained' => $this->consent->retained($offer)->count(),
            'needs' => $this->runner->results($offer, $request->user()),
        ];
    }
}
