<?php

namespace App\Http\Resources\V1\Promoter;

use App\Enums\NeedStatus;
use App\Enums\ProposalStatus;
use App\Models\Need;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Suggestion;
use App\Support\Promoter\Reservations;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * « Proposition d’équipe » / « Détail de la réservation » : étapes, équipe proposée (ou composition demandée pendant
 * la recherche), ouvriers encore recherchés, conditions, acompte et solde, actions possibles et historique.
 *
 * @mixin Offer
 */
class ReservationDetailResource extends ReservationResource
{
    /**
     * Étapes de la réservation : titre, précision.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const STEPS = [
        ['Demande publiée', 'Besoins transmis aux ouvriers'],
        ['Recherche d’ouvriers', 'Contact des profils disponibles'],
        ['Offre reçue', 'Acceptez ou refusez l’offre'],
        ['Acompte et confirmation', 'Réservation validée après paiement'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);
        $reservations = app(Reservations::class);
        $state = $base['state']['value'];
        $proposal = in_array($state, ['a_valider', 'acompte', 'confirmee'], true) ? $reservations->latestProposal($this->resource) : null;
        $proposal?->loadMissing(['suggestions.worker.photoMedia', 'suggestions.worker.workerTrades.trade', 'suggestions.worker.residenceCommune', 'suggestions.need.trade']);

        return [
            ...$base,
            'header' => match ($state) {
                'recherche' => 'Réservation en cours',
                'a_valider' => 'Offre reçue',
                default => 'Détail de la réservation',
            },
            'steps' => $this->steps($state),
            'team_title' => $proposal ? 'Équipe proposée' : 'Composition demandée',
            'lines' => $proposal ? $this->proposedLines($proposal) : $this->requestedLines(),
            'partial' => $proposal?->is_partial ? [
                'label' => $proposal->proposed_count.'/'.$proposal->requested_count.' ouvriers trouvés',
                'percent' => (int) round($proposal->proposed_count / $proposal->requested_count * 100),
                'hint' => 'Acompte calculé sur '.$proposal->proposed_count.' ouvriers · '.($missing = $proposal->requested_count - $proposal->proposed_count).' encore recherché'.($missing > 1 ? 's' : ''),
            ] : null,
            'missing' => $proposal ? $this->missingLines($proposal) : [],
            'conditions' => $this->conditions($base, $proposal),
            'balance_amount' => $base['total_amount'] && $base['deposit_amount'] ? $base['total_amount'] - $base['deposit_amount'] : null,
            'proposal' => $proposal ? [
                'id' => $proposal->id,
                'reference' => $proposal->reference,
                'status' => $this->status($proposal->status),
                'expires_at' => $proposal->expires_at?->toIso8601String(),
                'accept_label' => $proposal->is_partial ? 'Accepter '.$proposal->proposed_count.' ouvriers et payer' : 'Accepter et payer l’acompte',
            ] : null,
            'can_accept' => (bool) $proposal?->isPending(),
            'can_refuse' => (bool) $proposal?->isPending(),
            'can_pay_deposit' => $state === 'acompte',
            'history' => $this->proposals->sortBy('sequence')->map(fn (Proposal $item) => [
                'id' => $item->id,
                'reference' => $item->reference,
                'status' => $this->status($item->status),
                'proposed_count' => $item->proposed_count,
                'total_amount' => $item->total_amount,
                'published_at' => $item->published_at?->toIso8601String(),
                'decided_at' => $item->decided_at?->toIso8601String(),
                'refusal_reason' => $item->refusalReason?->only(['code', 'label']),
                'refusal_comment' => $item->refusal_comment,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(string $state): array
    {
        $done = ['recherche' => 1, 'a_valider' => 2, 'acompte' => 3, 'confirmee' => 4][$state] ?? 0;

        return array_map(fn (int $i) => [
            'title' => self::STEPS[$i][0],
            'text' => self::STEPS[$i][1],
            'state' => match (true) {
                $i < $done => 'fait',
                $i === $done => 'en_cours',
                default => 'a_venir',
            },
        ], array_keys(self::STEPS));
    }

    /**
     * Équipe proposée, par métier, avec le profil et le tarif de chaque ouvrier.
     *
     * @return list<array<string, mixed>>
     */
    private function proposedLines(Proposal $proposal): array
    {
        return $proposal->suggestions->groupBy('need_id')->map(function (Collection $items): array {
            /** @var Need $need */
            $need = $items->first()->need;

            return [
                'trade' => $need->trade->only(['id', 'code', 'label']),
                'count' => $items->count(),
                'workers' => $items->sortBy('rank')->map(fn (Suggestion $suggestion) => [
                    ...CatalogWorkerResource::make($suggestion->worker)->resolve(),
                    'daily_rate' => $suggestion->promoter_daily_rate,
                ])->values()->all(),
                'daily_amount' => (int) $items->sum('promoter_daily_rate'),
            ];
        })->values()->all();
    }

    /**
     * Composition demandée pendant la recherche : métier, effectif, tarif proposé, ouvriers choisis.
     *
     * @return list<array<string, mixed>>
     */
    private function requestedLines(): array
    {
        return $this->needs->reject(fn (Need $need) => $need->status === NeedStatus::Annule)->map(fn (Need $need) => [
            'trade' => $need->trade->only(['id', 'code', 'label']),
            'count' => $need->headcount,
            'daily_rate' => $need->indicative_daily_budget,
            'requested_workers' => $need->requestedWorkers->map(fn ($requested) => [
                'id' => $requested->worker_id,
                'name' => trim($requested->worker->first_name.' '.$requested->worker->last_name),
                'daily_rate' => $requested->promoter_daily_rate,
            ])->values()->all(),
            'status' => 'Recherche en cours',
        ])->values()->all();
    }

    /**
     * Ouvriers encore recherchés : manquants de la proposition en attente, ou besoins de relance après acceptation.
     *
     * @return list<array<string, mixed>>
     */
    private function missingLines(Proposal $proposal): array
    {
        if ($proposal->status === ProposalStatus::AValider) {
            $proposed = $proposal->suggestions->countBy('need_id');

            return $this->needs->filter(fn (Need $need) => $need->status === NeedStatus::PropositionPromoteur || $proposed->has($need->id) || $need->status === NeedStatus::Matching)
                ->map(fn (Need $need) => ['need' => $need, 'missing' => $need->headcount - $proposed->get($need->id, 0)])
                ->filter(fn (array $item) => $item['missing'] > 0)
                ->map(fn (array $item) => $this->missingLine($item['need'], $item['missing']))
                ->values()->all();
        }

        return $this->needs->filter(fn (Need $need) => $need->duplicated_from_need_id && in_array($need->status, [NeedStatus::Matching, NeedStatus::ContactOuvriers, NeedStatus::PropositionPromoteur], true))
            ->map(fn (Need $need) => $this->missingLine($need, $need->headcount))
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function missingLine(Need $need, int $count): array
    {
        return ['trade' => $need->trade->only(['id', 'code', 'label']), 'count' => $count, 'daily_rate' => $need->indicative_daily_budget, 'status' => 'Recherche en cours'];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return list<array{label: string, value: string|null}>
     */
    private function conditions(array $base, ?Proposal $proposal): array
    {
        $money = fn (?int $amount) => $amount ? $this->money($amount) : null;

        return [
            ['label' => 'Chantier', 'value' => $this->site->label],
            ['label' => 'Période', 'value' => $base['start_date'] && $base['end_date'] ? $base['start_date'].' → '.$base['end_date'] : null],
            ['label' => 'Durée', 'value' => $base['days'] ? $base['days'].' jours' : null],
            ['label' => 'Main-d’œuvre', 'value' => $base['daily_amount'] ? $money($base['daily_amount']).' / jour' : null],
            ['label' => 'Coût des ouvriers', 'value' => $money($base['workers_amount'])],
            ['label' => 'Commission O’CHANTIER ('.str_replace('.', ',', (string) (float) $base['commission_percent']).' %)', 'value' => $money($base['commission_amount'])],
            ['label' => $proposal ? 'Total' : 'Total estimé', 'value' => $money($base['total_amount'])],
        ];
    }
}
