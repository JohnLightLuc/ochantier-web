<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\ProposalStatus;
use App\Enums\SiteStatus;
use App\Http\Resources\V1\Resource;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Site;
use App\Support\Media\MediaStore;
use App\Support\Promoter\ContractorOffers;
use App\Support\Promoter\OfferDraft;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\SiteManager;
use Illuminate\Http\Request;

/**
 * Chantier dans « Mes chantiers » : photo, localisation, calendrier, badge et actions possibles.
 *
 * @mixin Site
 */
class SiteResource extends Resource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sites = app(SiteManager::class);
        $offer = app(OfferDraft::class)->summary($this->resource);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'internal_reference' => $this->internal_reference,
            'title' => $this->label,
            'work_type' => $this->status($this->work_type),
            'property_type' => $this->constructionType?->only(['id', 'code', 'label']),
            'photo_url' => $this->mainImageMedia ? app(MediaStore::class)->temporaryUrl($this->mainImageMedia) : null,
            'address' => [
                'address' => $this->address,
                'commune' => $this->commune?->only(['id', 'name']),
                'neighborhood' => $this->neighborhood?->only(['id', 'name']),
                'location' => $this->location,
            ],
            'start_date' => $this->date($this->planned_start_date),
            'end_date' => $this->date($this->planned_end_date),
            'duration_days' => $this->planned_duration_days,
            'offer_summary' => $offer ? [
                'type' => $offer['type'],
                'workers_count' => $offer['workers_count'] ?? null,
                'amount' => $offer['amount'] ?? $offer['estimate'] ?? null,
                'payment_mode' => $offer['payment_mode'] ?? null,
            ] : null,
            // Nombre d'ouvriers RÉELLEMENT demandés toutes offres confondues,
            // ajouts post-publication inclus. `offer_summary.workers_count`
            // ci-dessus ne reflète que l'offre initiale / le brouillon et
            // devient faux dès qu'une réservation supplémentaire est créée
            // (`Reservations::createAdditional`). Même formule que
            // `SiteDetailResource.workers_total`, pour que la liste et le
            // détail soient toujours cohérents.
            'workers_total' => (int) $this->offers()
                ->where('status', '!=', OfferStatus::Annulee)
                ->with('needs')
                ->get()
                ->sum(fn (Offer $offer) => $offer->needs
                    ->reject(fn (Need $need) => $need->status === NeedStatus::Annule)
                    ->sum('headcount')),
            'pending_proposal' => ($proposal = $this->proposals()
                ->whereIn('status', [ProposalStatus::AValider, ProposalStatus::Acceptee])
                ->with(['suggestions.worker', 'suggestions.need.trade', 'offer'])
                ->latest('published_at')
                ->first()
            ) ? $this->pendingProposal($proposal) : null,
            'status' => $this->status($this->status),
            'display_status' => $sites->displayStatus($this->resource),
            'schedule' => $sites->schedule($this->resource),
            'quote_request' => ($quote = app(QuoteRequests::class)->current($this->resource)) ? [
                'id' => $quote->id,
                'reference' => $quote->reference,
                'status' => $this->status($quote->status),
                'action' => app(QuoteRequests::class)->action($quote),
            ] : null,
            'contractor_offers' => in_array($this->status, [SiteStatus::Recherche, SiteStatus::Paiement], true) && ($action = app(ContractorOffers::class)->action($this->resource)) ? ['action' => $action] : null,
            'can_edit' => $this->status === SiteStatus::Brouillon,
            'can_delete' => $this->status === SiteStatus::Brouillon && ! app(QuoteRequests::class)->hasOrder($this->resource),
            'missing_for_publication' => $this->status === SiteStatus::Brouillon ? $sites->missingForPublication($this->resource) : [],
            'client_uuid' => $this->client_uuid,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Récapitulatif complet d'une proposition en attente d'action promoteur
     * (statut `à valider` ou `acceptée`). Le mobile l'affiche sur la bannière
     * de la carte chantier ET sur la page « Proposition d'équipe » : un seul
     * payload évite d'enchaîner un second appel `/reservations/{id}`.
     *
     * Les montants (`total_amount`, `deposit_amount`, …) sont ceux calculés
     * et stockés par l'admin au moment de la publication (`Proposal` model) ;
     * `balance_amount` = total − acompte.
     *
     * `lines[]` agrège les `suggestions` par besoin (donc par métier) :
     * chaque ligne porte les ouvriers nommément proposés avec leur tarif
     * promoteur (`worker_daily_rate`, fallback au tarif retenu du besoin).
     *
     * @return array<string, mixed>
     */
    private function pendingProposal(Proposal $proposal): array
    {
        $deposit = (int) $proposal->deposit_amount;
        $total = (int) $proposal->total_amount;

        return [
            'id' => $proposal->id,
            'reservation_id' => $proposal->offer_id,
            'status' => $this->status($proposal->status),
            'days' => (int) $proposal->days,
            'proposed_count' => (int) $proposal->proposed_count,
            'is_partial' => (bool) $proposal->is_partial,
            'daily_amount' => (int) $proposal->daily_amount,
            'workers_amount' => (int) $proposal->workers_amount,
            'commission_amount' => (int) $proposal->commission_amount,
            'total_amount' => $total,
            'deposit_amount' => $deposit,
            'balance_amount' => max(0, $total - $deposit),
            'expires_at' => $proposal->expires_at?->toIso8601String(),
            'lines' => $this->pendingProposalLines($proposal),
        ];
    }

    /**
     * Agrège les `suggestions` d'une proposition par `need_id` : une entrée
     * par métier, les ouvriers nommés groupés dessous. Shape alignée avec
     * `SiteDetailResource.composition[]` pour que le mobile puisse
     * potentiellement réutiliser le même parseur.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingProposalLines(Proposal $proposal): array
    {
        $byNeed = [];
        foreach ($proposal->suggestions as $suggestion) {
            $need = $suggestion->need;
            if ($need === null) {
                continue;
            }
            $key = $need->id;
            if (! isset($byNeed[$key])) {
                $byNeed[$key] = [
                    'trade' => $need->trade->only(['id', 'code', 'label']),
                    'count' => (int) $need->headcount,
                    'daily_rate' => (int) $need->indicative_daily_budget,
                    'workers' => [],
                ];
            }
            $worker = $suggestion->worker;
            if ($worker === null) {
                continue;
            }
            $byNeed[$key]['workers'][] = [
                'id' => $worker->id,
                'name' => trim($worker->first_name.' '.$worker->last_name),
                'daily_rate' => (int) ($suggestion->worker_daily_rate
                    ?? $need->indicative_daily_budget),
            ];
        }

        return array_values($byNeed);
    }
}
