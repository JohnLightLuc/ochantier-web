<?php

namespace App\Support\Admin;

use App\Models\ContractorPayoutItem;
use App\Support\Promoter\ContractorOffers;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registre des commissions O’CHANTIER PRO : une ligne par encaissement qui porte une commission ou des frais de
 * service.
 *
 * - Demande de devis : frais de service de la proposition commandée, payés par le promoteur avec la commande.
 * - Offre contractuelle : part de commission de l’acompte et de chaque échéance du forfait, au taux figé de la
 *   mission ; le reste est la part nette du professionnel.
 * - Recherche d’effectifs : frais de service payés par le professionnel avec l’acompte.
 *
 * O’CHANTIER encaisse tout l’argent : une commission est « collectée » dès que son paiement a réussi, « à collecter »
 * tant qu’il est attendu. La part nette du professionnel (frais de visite, forfait) lui sera reversée.
 */
class ProCommissionLedger
{
    public const SOURCES = ['demande_devis' => 'Demande de devis', 'offre_contractuelle' => 'Offre contractuelle', 'recherche_effectifs' => 'Recherche d’effectifs'];

    public const STATUSES = ['a_collecter' => 'À collecter', 'collectee' => 'Collectée', 'remboursee' => 'Remboursée'];

    /**
     * Lignes filtrées, de la plus récente à la plus ancienne. Filtres : source, status, contractor_id, search
     * (référence, chantier, professionnel), from et to (date de paiement, ou d’échéance pour ce qui reste à collecter).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(array $filters = []): Collection
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->startOfDay() : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->endOfDay() : null;
        $sources = $filters['source'] ?? null;

        $payouts = ContractorPayoutItem::query()->with('payout:id,reference,status')->get()->keyBy('source_key');

        return collect()
            ->concat(! $sources || $sources === 'demande_devis' ? $this->quotes() : [])
            ->concat(! $sources || $sources === 'offre_contractuelle' ? $this->contracts() : [])
            ->concat(! $sources || $sources === 'recherche_effectifs' ? $this->staffing() : [])
            ->filter(fn (array $line) => ! isset($filters['status']) || $line['status']['value'] === $filters['status'])
            ->filter(fn (array $line) => ! isset($filters['contractor_id']) || $line['contractor']['id'] === $filters['contractor_id'])
            ->filter(fn (array $line) => $search === '' || str_contains(mb_strtolower($line['reference'].' '.$line['site']['label'].' '.$line['contractor']['name'].' '.$line['payer']), $search))
            ->filter(function (array $line) use ($from, $to): bool {
                $at = $line['date'] ? CarbonImmutable::parse($line['date']) : null;

                return (! $from || ($at && $at->gte($from))) && (! $to || ($at && $at->lte($to)));
            })
            ->map(function (array $line) use ($payouts): array {
                $payout = $payouts->get($line['id'])?->payout;

                return [...$line, 'payout' => $payout ? ['id' => $payout->id, 'reference' => $payout->reference, 'status' => ['value' => $payout->status->value, 'label' => $payout->status->label()]] : null];
            })
            ->sortByDesc(fn (array $line) => ($line['date'] ?? '').'|'.$line['id'])
            ->values();
    }

    /**
     * Parts nettes encore dues à un professionnel : encaissées, service rendu (visite faite, échéance payée) et pas
     * encore réunies dans un versement.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function payable(?string $contractorId = null): Collection
    {
        return $this->lines(array_filter(['status' => 'collectee', 'contractor_id' => $contractorId]))
            ->filter(fn (array $line) => $line['service_done'] && $line['professional_net'] > 0 && $line['payout'] === null)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $lines = $this->lines($filters);

        return new LengthAwarePaginator($lines->forPage($page, $perPage)->values(), $lines->count(), $perPage, $page);
    }

    /**
     * Totaux des lignes filtrées : commission par statut et par source, montant encaissé et part nette des
     * professionnels.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(array $filters = []): array
    {
        $lines = $this->lines($filters);
        $sum = fn (Collection $rows, string $key): int => (int) $rows->sum($key);

        return [
            'commission' => collect(self::STATUSES)->map(fn (string $label, string $status) => $sum($lines->where('status.value', $status), 'commission'))->all(),
            'by_source' => collect(self::SOURCES)->map(fn (string $label, string $source) => [
                'collected' => $sum($lines->where('source.value', $source)->where('status.value', 'collectee'), 'commission'),
                'to_collect' => $sum($lines->where('source.value', $source)->where('status.value', 'a_collecter'), 'commission'),
            ])->all(),
            'collected_amount' => $sum($lines->where('status.value', 'collectee'), 'base'),
            'professional_net' => $sum($lines->where('status.value', 'collectee'), 'professional_net'),
            'count' => $lines->count(),
        ];
    }

    /**
     * Frais de service des propositions de devis commandées (payées avec la commande des devis).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function quotes(): Collection
    {
        return DB::table('quote_bids as b')
            ->join('quote_request_trades as l', 'l.id', '=', 'b.quote_request_trade_id')
            ->join('quote_requests as r', 'r.id', '=', 'l.quote_request_id')
            ->join('sites as s', 's.id', '=', 'r.site_id')
            ->join('contractors as c', 'c.id', '=', 'b.contractor_id')
            ->join('trades as t', 't.id', '=', 'l.trade_id')
            ->leftJoin('payment_transactions as tx', 'tx.id', '=', 'r.payment_transaction_id')
            ->where('b.status', 'choisie')
            ->whereNotNull('r.ordered_at')
            ->select('b.id', 'l.id as line_id', 'r.reference', 's.id as site_id', 's.label as site', 'c.id as contractor_id', 'c.name as contractor', 't.label as trade')
            ->addSelect('b.amount', 'b.fee_amount', 'b.service_fee_amount', 'b.service_fee_percent', 'b.visit_status', 'b.visit_confirmation', 'tx.reference as payment_reference', 'r.ordered_at', 'tx.completed_at')
            ->selectRaw($this->promoterName('s').' as payer')
            ->get()
            ->map(fn (object $row) => $this->line(
                id: 'devis:'.$row->id,
                source: 'demande_devis',
                reference: $row->reference,
                label: 'Visite · '.$row->trade,
                opportunity: ['type' => 'demande_devis', 'id' => $row->line_id],
                site: ['id' => $row->site_id, 'label' => $row->site],
                contractor: ['id' => $row->contractor_id, 'name' => $row->contractor],
                payer: $row->payer,
                base: (int) $row->amount,
                rate: (float) $row->service_fee_percent,
                commission: (int) $row->service_fee_amount,
                net: (int) $row->fee_amount,
                status: 'collectee',
                date: $row->completed_at ?? $row->ordered_at,
                payment: $row->payment_reference,
                // Frais de visite dus une fois la visite confirmée (par le promoteur, d’office ou par le Back Office).
                serviceDone: $row->visit_status === 'realisee' && in_array($row->visit_confirmation, ['confirmee', 'automatique'], true),
            ));
    }

    /**
     * Part de commission de l’acompte et des échéances des forfaits d’entrepreneurs, au taux figé de la mission.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function contracts(): Collection
    {
        $paidAt = DB::table('payment_allocations as pa')->join('payment_transactions as ptx', 'ptx.id', '=', 'pa.transaction_id')
            ->where('ptx.status', 'reussie')->groupBy('pa.payment_schedule_id')->select('pa.payment_schedule_id')->selectRaw('max(ptx.completed_at) as paid_at');

        return DB::table('payment_schedules as ps')
            ->join('missions as m', 'm.id', '=', 'ps.mission_id')
            ->join('pricing_snapshots as p', 'p.id', '=', 'm.pricing_snapshot_id')
            ->join('needs as n', 'n.id', '=', 'm.need_id')
            ->join('offers as o', 'o.id', '=', 'n.offer_id')
            ->join('contractor_bids as cb', 'cb.id', '=', 'o.contractor_bid_id')
            ->join('contractors as c', 'c.id', '=', 'cb.contractor_id')
            ->join('sites as s', 's.id', '=', 'm.site_id')
            ->join('trades as t', 't.id', '=', 'n.trade_id')
            ->leftJoinSub($paidAt, 'paid', 'paid.payment_schedule_id', '=', 'ps.id')
            ->where('t.code', ContractorOffers::TRADE)
            ->whereNotIn('ps.status', ['annulee'])
            ->orderBy('ps.mission_id')->orderByRaw("ps.schedule_type = 'acompte' DESC")->orderBy('ps.sequence')
            ->select('ps.id', 'ps.mission_id', 'ps.reference as schedule_reference', 'ps.schedule_type', 'ps.sequence', 'ps.amount_due', 'ps.status', 'ps.due_date', 'paid.paid_at')
            ->addSelect('o.id as offer_id', 'o.reference', 's.id as site_id', 's.label as site', 'c.id as contractor_id', 'c.name as contractor', 'p.y_rate', 'p.promoter_commission_amount', 'p.total_promoter_amount')
            ->selectRaw($this->promoterName('s').' as payer')
            ->get()
            ->groupBy('mission_id')
            ->flatMap(function (Collection $rows) {
                // La dernière échéance reçoit l’arrondi : la somme des lignes est exactement la commission du forfait.
                $shares = $rows->map(fn (object $row) => $row->total_promoter_amount > 0 ? (int) round($row->amount_due * $row->promoter_commission_amount / $row->total_promoter_amount) : 0)->values()->all();
                $shares[count($shares) - 1] = (int) $rows->first()->promoter_commission_amount - array_sum(array_slice($shares, 0, -1));

                return $rows->values()->map(fn (object $row, int $index) => [$row, $shares[$index]]);
            })
            ->map(function (array $pair) {
                [$row, $commission] = $pair;
                $base = (int) $row->amount_due;
                $deposit = $row->schedule_type === 'acompte';

                return $this->line(
                    id: 'forfait:'.$row->id,
                    source: 'offre_contractuelle',
                    reference: $row->reference,
                    label: $deposit ? 'Acompte' : 'Échéance '.$row->sequence,
                    opportunity: ['type' => 'offre_contractuelle', 'id' => $row->offer_id],
                    site: ['id' => $row->site_id, 'label' => $row->site],
                    contractor: ['id' => $row->contractor_id, 'name' => $row->contractor],
                    payer: $row->payer,
                    base: $base,
                    rate: (float) $row->y_rate,
                    commission: $commission,
                    net: $base - $commission,
                    status: match ($row->status) {
                        'payee' => 'collectee',
                        'remboursee' => 'remboursee',
                        default => 'a_collecter',
                    },
                    date: $row->paid_at ?? $row->due_date,
                    payment: $row->schedule_reference,
                    serviceDone: $row->status === 'payee',
                );
            });
    }

    /**
     * Frais de service des recherches d’effectifs, payés par le professionnel avec l’acompte des ouvriers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function staffing(): Collection
    {
        return DB::table('staffing_requests as sr')
            ->join('contractors as c', 'c.id', '=', 'sr.contractor_id')
            ->join('sites as s', 's.id', '=', 'sr.site_id')
            ->leftJoin('payment_transactions as tx', 'tx.id', '=', 'sr.deposit_transaction_id')
            ->whereIn('sr.status', ['offre', 'payee'])
            ->select('sr.id', 'sr.reference', 'sr.status', 'sr.deposit_amount', 'sr.service_fee_amount', 'sr.service_fee_percent', 'sr.offered_at', 'sr.paid_at', 'tx.reference as payment_reference')
            ->addSelect('s.id as site_id', 's.label as site', 'c.id as contractor_id', 'c.name as contractor')
            ->get()
            ->map(fn (object $row) => $this->line(
                id: 'effectifs:'.$row->id,
                source: 'recherche_effectifs',
                reference: $row->reference,
                label: 'Frais de service',
                opportunity: ['type' => 'recherche_effectifs', 'id' => $row->id],
                site: ['id' => $row->site_id, 'label' => $row->site],
                contractor: ['id' => $row->contractor_id, 'name' => $row->contractor],
                payer: $row->contractor,
                base: (int) $row->deposit_amount + (int) $row->service_fee_amount,
                rate: (float) $row->service_fee_percent,
                commission: (int) $row->service_fee_amount,
                net: null,
                status: $row->status === 'payee' ? 'collectee' : 'a_collecter',
                date: $row->paid_at ?? $row->offered_at,
                payment: $row->payment_reference,
                serviceDone: false,
            ));
    }

    /**
     * @param  array{type: string, id: string}  $opportunity
     * @param  array{id: string, label: string}  $site
     * @param  array{id: string, name: string}  $contractor
     * @return array<string, mixed>
     */
    private function line(string $id, string $source, string $reference, string $label, array $opportunity, array $site, array $contractor, ?string $payer, int $base, float $rate, int $commission, ?int $net, string $status, ?string $date, ?string $payment, bool $serviceDone): array
    {
        return [
            'id' => $id,
            'source' => ['value' => $source, 'label' => self::SOURCES[$source]],
            'reference' => $reference,
            'label' => $label,
            'opportunity' => $opportunity,
            'site' => $site,
            'contractor' => $contractor,
            'payer' => $payer ?? '—',
            'base' => $base,
            'rate' => $rate,
            'commission' => $commission,
            'professional_net' => $net,
            'status' => ['value' => $status, 'label' => self::STATUSES[$status]],
            'date' => $date ? CarbonImmutable::parse($date)->toIso8601String() : null,
            'payment_reference' => $payment,
            'service_done' => $serviceDone,
        ];
    }

    private function promoterName(string $site): string
    {
        return "(SELECT concat_ws(' ', u.first_name, u.last_name) FROM promoter_profiles pp JOIN users u ON u.id = pp.user_id WHERE pp.id = {$site}.promoter_profile_id)";
    }
}
