<?php

namespace App\Http\Controllers\Api\V1\Promoter;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Promoter\RefuseProposalRequest;
use App\Http\Requests\V1\Promoter\ReservationRequest;
use App\Http\Resources\V1\Promoter\ReservationDetailResource;
use App\Http\Resources\V1\Promoter\ReservationResource;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\ReasonCode;
use App\Models\Site;
use App\Support\Promoter\Reservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * « Réservations » et « Proposition d’équipe » : suivi des recherches, décision sur les propositions, nouvelle
 * réservation sur un chantier publié.
 */
class ReservationController extends Controller
{
    public function __construct(private Reservations $reservations) {}

    /**
     * Réservations du promoteur, de la plus récente à la plus ancienne. Filtres : filter (recherche, a_valider,
     * confirmees, refusees), site_id. Le nombre de réservations par onglet est renvoyé dans meta.counts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(Reservations::FILTERS)],
            'site_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = fn () => $this->reservations->query($request->user())->when($filters['site_id'] ?? null, fn ($query, $site) => $query->where('site_id', $site));
        $offers = $base()
            ->with(Reservations::WITH)
            ->when($filters['filter'] ?? null, fn ($query, $filter) => $this->reservations->filter($query, $filter))
            ->latest('submitted_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $counts = ['toutes' => $base()->count()];

        foreach (Reservations::FILTERS as $filter) {
            $counts[$filter] = $this->reservations->filter($base(), $filter)->count();
        }

        $sites = $base()->with('site')->get()->pluck('site')->unique('id')->map(fn (Site $site) => ['id' => $site->id, 'title' => $site->label])->values();

        return ReservationResource::collection($offers)->additional(['meta' => ['counts' => $counts, 'sites' => $sites]]);
    }

    public function show(Offer $reservation): ReservationDetailResource
    {
        return ReservationDetailResource::make($reservation->load(Reservations::WITH));
    }

    /**
     * « Accepter et payer l’acompte » : les ouvriers sont réservés le temps du paiement de l’acompte.
     */
    public function accept(Request $request, Proposal $proposal): ReservationDetailResource
    {
        $proposal = $this->reservations->accept($proposal, $request->user());

        return ReservationDetailResource::make($proposal->offer->load(Reservations::WITH))
            ->additional(['message' => 'Proposition acceptée — réglez l’acompte pour confirmer la réservation.']);
    }

    public function refuse(RefuseProposalRequest $request, Proposal $proposal): ReservationDetailResource
    {
        $reason = ReasonCode::query()->where('category', Reservations::REFUSAL_CATEGORY)->where('code', $request->input('reason_code'))->firstOrFail();
        $proposal = $this->reservations->refuse($proposal, $request->user(), $reason, $request->input('comment'));

        return ReservationDetailResource::make($proposal->offer->load(Reservations::WITH))
            ->additional(['message' => 'Proposition refusée — nous cherchons une autre équipe.']);
    }

    /**
     * Nouvelle réservation sur un chantier publié.
     */
    public function store(ReservationRequest $request, Site $site): JsonResponse
    {
        $offer = $this->reservations->createAdditional($site, $request->user(), $request->validated());

        return ReservationDetailResource::make($offer->load(Reservations::WITH))
            ->additional(['message' => 'Recherche publiée pour « '.$site->label.' »'])
            ->response()
            ->setStatusCode(201);
    }
}
