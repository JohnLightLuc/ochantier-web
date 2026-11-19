<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Document;
use App\Models\Need;
use App\Models\Offer;
use App\Models\OfferMilestone;
use App\Models\Site;
use App\Support\Promoter\ContractorOffers;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\Reservations;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\Request;

/**
 * « Détail chantier » : la carte, plus la description, les accès, le responsable, les réservations et les documents
 * joints. La première offre du chantier est la réservation initiale (« offer.published ») ; les suivantes, ajoutées
 * après la publication, sont des réservations supplémentaires.
 *
 * @mixin Site
 */
class SiteDetailResource extends SiteResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sites = app(SiteManager::class);
        $responsible = $this->responsibleUser;
        $offers = $this->offers()
            ->with(['needs.trade', 'needs.requestedWorkers.worker', 'milestones', 'proposals'])
            ->oldest()
            ->oldest('id')
            ->get();
        $initial = $offers->first();
        $liveOffers = $offers->reject(fn (Offer $offer) => $offer->status === OfferStatus::Annulee);

        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'access_instructions' => $this->access_instructions,
            'responsible' => $responsible ? $sites->person($responsible, $responsible->is($request->user()) ? 'Moi-même' : null) : null,
            'offer' => [
                'draft' => app(OfferDraft::class)->summary($this->resource),
                'published' => $initial ? $this->published($initial) : null,
            ],
            'reservations' => $offers->map(fn (Offer $offer) => $this->reservation($offer, $offer->is($initial)))->values()->all(),
            // Composition demandée, agrégée par métier toutes offres non
            // annulées (initiale + supplémentaires). Évite au mobile de
            // reconstruire lui-même la somme depuis `reservations[].needs` et
            // garantit une règle de filtrage unique côté serveur.
            'composition' => $this->composition($liveOffers),
            'workers_total' => (int) $liveOffers
                ->sum(fn (Offer $offer) => $offer->needs->reject(fn (Need $need) => $need->status === NeedStatus::Annule)->sum('headcount')),
            'contractor_offers' => ($contract = app(ContractorOffers::class)->openOffer($this->resource)) ? app(ContractorOffers::class)->summary($contract) : null,
            'quote_request' => ($quote = app(QuoteRequests::class)->current($this->resource)) ? app(QuoteRequests::class)->summary($quote) : null,
            'documents' => $sites->activeDocuments($this->resource)
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve())
                ->values()
                ->all(),
        ];
    }

    /**
     * Fusion des besoins (hors annulés) de toutes les offres (hors annulées)
     * par métier : une entrée par `trade`, somme des `headcount`, tarif de la
     * première ligne non nulle, et concaténation des `requested_workers[]`.
     * Même shape que `offer.draft.lines[]` pour que le mobile puisse la
     * consommer avec le même parseur.
     *
     * @param  \Illuminate\Support\Collection<int, Offer>  $offers  Offres non annulées.
     * @return list<array<string, mixed>>
     */
    private function composition($offers): array
    {
        $byTrade = [];
        foreach ($offers as $offer) {
            foreach ($offer->needs as $need) {
                if ($need->status === NeedStatus::Annule) {
                    continue;
                }
                $tradeId = $need->trade->id;
                if (! isset($byTrade[$tradeId])) {
                    $byTrade[$tradeId] = [
                        'trade' => $need->trade->only(['id', 'code', 'label']),
                        'headcount' => 0,
                        'daily_rate' => null,
                        'requested_workers' => [],
                    ];
                }
                $byTrade[$tradeId]['headcount'] += (int) $need->headcount;
                if ($byTrade[$tradeId]['daily_rate'] === null && $need->indicative_daily_budget !== null) {
                    $byTrade[$tradeId]['daily_rate'] = $need->indicative_daily_budget;
                }
                foreach ($need->requestedWorkers as $requested) {
                    $byTrade[$tradeId]['requested_workers'][] = [
                        'id' => $requested->worker->id,
                        'name' => trim($requested->worker->first_name.' '.$requested->worker->last_name),
                        'daily_rate' => $requested->promoter_daily_rate,
                    ];
                }
            }
        }

        return array_values($byTrade);
    }

    /**
     * Réservation du chantier : l’offre publiée, sa nature (initiale ou supplémentaire), sa période, son état côté
     * promoteur et le nombre d’ouvriers demandés.
     *
     * @return array<string, mixed>
     */
    private function reservation(Offer $offer, bool $initial): array
    {
        return [
            ...$this->published($offer),
            'kind' => $initial
                ? ['value' => 'initiale', 'label' => 'Réservation initiale']
                : ['value' => 'supplementaire', 'label' => 'Réservation supplémentaire'],
            'start_date' => $this->date($offer->start_date),
            'end_date' => $this->date($offer->end_date),
            'state' => app(Reservations::class)->state($offer),
            'workers_count' => (int) $offer->needs->sum('headcount'),
        ];
    }

    /**
     * Offre transmise au Back office : référence, statut, besoins par métier ou échéances du forfait.
     *
     * @return array<string, mixed>
     */
    private function published(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'reference' => $offer->reference,
            'type' => $this->status($offer->offer_type),
            'status' => $this->status($offer->status),
            'submitted_at' => $offer->submitted_at?->toIso8601String(),
            'contract_amount' => $offer->contract_total_amount,
            'payment_mode' => $this->status($offer->payment_mode),
            'needs' => $offer->needs->map(fn (Need $need) => [
                'id' => $need->id,
                'reference' => $need->reference,
                'trade' => $need->trade->only(['id', 'code', 'label']),
                'headcount' => $need->headcount,
                'daily_rate' => $need->indicative_daily_budget,
                'status' => $this->status($need->status),
            ])->values()->all(),
            'milestones' => $offer->milestones->sortBy('sequence')->map(fn (OfferMilestone $milestone) => [
                'label' => $milestone->label,
                'amount' => $milestone->amount,
                'due_date' => $this->date($milestone->due_date),
            ])->values()->all(),
        ];
    }
}
