<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestTrade;
use App\Models\QuoteVersion;
use App\Models\Trade;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Demandes de devis du Back Office : une ligne par corps de métier d’une demande (un professionnel choisi, une
 * visite, un devis). Le statut affiché est calculé à partir de la demande, de la ligne, de la proposition choisie
 * et des versions du devis envoyées.
 */
class QuoteRequestDirectory
{
    /**
     * Statuts affichés, dans l’ordre du parcours.
     */
    public const STATUSES = [
        'ouverte' => 'Ouverte',
        'visite_programmee' => 'Visite programmée',
        'report_propose' => 'Report de visite proposé',
        'devis_en_preparation' => 'Devis en préparation',
        'devis_envoye' => 'Devis envoyé',
        'devis_recu' => 'Devis reçu',
        'expiree' => 'Expirée',
        'annulee' => 'Annulée',
    ];

    /**
     * Calcul du statut affiché d’une ligne (qr : demande, sb : proposition choisie). « :now » est remplacé par l’heure
     * de l’application (statusSql()).
     */
    private const STATUS_SQL = "CASE WHEN qr.status = 'annule' THEN 'annulee'"
        ." WHEN quote_request_trades.status = 'recherche' AND qr.response_deadline < :now THEN 'expiree'"
        ." WHEN quote_request_trades.status = 'recherche' THEN 'ouverte'"
        ." WHEN quote_request_trades.status = 'commande' AND sb.visit_status = 'report_propose' THEN 'report_propose'"
        ." WHEN quote_request_trades.status = 'commande' AND sb.visit_status = 'realisee' THEN 'devis_en_preparation'"
        ." WHEN quote_request_trades.status = 'commande' THEN 'visite_programmee'"
        ." WHEN EXISTS (SELECT 1 FROM quote_versions qv WHERE qv.quote_request_trade_id = quote_request_trades.id AND qv.status = 'envoye') THEN 'devis_envoye'"
        ." ELSE 'devis_recu' END";

    /**
     * Statut affiché en SQL, la date limite comparée à l’heure de l’application.
     */
    public static function statusSql(): string
    {
        return str_replace(':now', DB::getPdo()->quote(now()->toIso8601String()).'::timestamptz', self::STATUS_SQL);
    }

    /**
     * Libellés des décisions du Back Office tracées dans le journal d’audit.
     */
    public const ADMIN_ACTIONS = [
        'quote_request.extended' => 'a prolongé la date limite',
        'quote_request.cancelled' => 'a annulé la demande',
        'quote_request.reminded' => 'a envoyé une relance',
    ];

    /**
     * Lignes filtrées. Filtres : search (référence, chantier, promoteur), trade (code), city_id, status (statuts
     * affichés).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<QuoteRequestTrade>
     */
    public function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return QuoteRequestTrade::query()
            ->join('quote_requests as qr', 'qr.id', '=', 'quote_request_trades.quote_request_id')
            ->join('sites', 'sites.id', '=', 'qr.site_id')
            ->leftJoin('quote_bids as sb', 'sb.id', '=', 'quote_request_trades.selected_bid_id')
            ->select('quote_request_trades.*')
            ->selectRaw(self::statusSql().' as display_status')
            ->with(['trade', 'quoteRequest.site.commune.city', 'quoteRequest.site.promoterProfile.user', 'selectedBid.contractor'])
            ->withCount(['bids as bids_count' => fn (Builder $query) => $query->whereNotNull('contractor_id')])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('qr.reference', 'ilike', '%'.$search.'%')
                ->orWhere('sites.label', 'ilike', '%'.$search.'%')
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('promoter_profiles')->join('users', 'users.id', '=', 'promoter_profiles.user_id')
                    ->whereColumn('promoter_profiles.id', 'sites.promoter_profile_id')
                    ->whereRaw("concat_ws(' ', users.first_name, users.last_name) ilike ?", ['%'.$search.'%']))))
            ->when($filters['trade'] ?? null, fn (Builder $query, string $code) => $query->whereIn('quote_request_trades.trade_id', Trade::query()->where('code', $code)->select('id')))
            ->when($filters['city_id'] ?? null, fn (Builder $query, string $city) => $query->whereIn('sites.commune_id', DB::table('communes')->where('city_id', $city)->select('id')))
            ->when($filters['status'] ?? [], fn (Builder $query, array $statuses) => $query->whereRaw(self::statusSql().' IN ('.implode(',', array_fill(0, count($statuses), '?')).')', $statuses))
            ->orderByDesc('qr.submitted_at')
            ->orderBy('quote_request_trades.created_at');
    }

    /**
     * Nombre de lignes par statut affiché.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $status = self::statusSql();
        $counts = $this->lines()->selectRaw($status.' as display_status, count(*) as total')->groupByRaw($status)->pluck('total', 'display_status');

        return collect(self::STATUSES)->mapWithKeys(fn (string $label, string $status) => [$status => (int) ($counts[$status] ?? 0)])->all();
    }

    /**
     * Options des filtres : métiers et villes des demandes.
     *
     * @return array{trades: mixed, cities: mixed}
     */
    public function options(): array
    {
        return [
            'trades' => Trade::query()->whereIn('id', DB::table('quote_request_trades')->select('trade_id'))->orderBy('sort_order')->orderBy('label')->get(['code', 'label']),
            'cities' => City::query()->whereIn('id', DB::table('sites')->join('quote_requests', 'quote_requests.site_id', '=', 'sites.id')->join('communes', 'communes.id', '=', 'sites.commune_id')->select('communes.city_id'))->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Lignes avec leur demande et leur proposition choisie, sans colonnes : base des comptages.
     */
    private function lines(): QueryBuilder
    {
        return DB::table('quote_request_trades')
            ->join('quote_requests as qr', 'qr.id', '=', 'quote_request_trades.quote_request_id')
            ->leftJoin('quote_bids as sb', 'sb.id', '=', 'quote_request_trades.selected_bid_id');
    }

    public function find(string $id): QuoteRequestTrade
    {
        abort_unless(str($id)->isUuid(), 404);

        return $this->query([])->where('quote_request_trades.id', $id)
            ->with(['quoteRequest.trades.trade', 'quoteRequest.paymentTransaction', 'quoteRequest.requestedByUser', 'bids' => fn ($query) => $query->whereNotNull('contractor_id')->with('contractor')->orderBy('created_at'), 'versions' => fn ($query) => $query->where('status', '!=', 'brouillon')->with('document.files')->orderByDesc('version')])
            ->firstOrFail();
    }

    /**
     * Statut affiché d’une ligne relue après une décision.
     */
    public function status(QuoteRequestTrade $line): string
    {
        return (string) $this->lines()->where('quote_request_trades.id', $line->getKey())->value(DB::raw(self::statusSql()));
    }

    /**
     * Chronologie de la ligne : publication, propositions, commande, visite, devis, annulation et décisions du
     * Back Office sur la demande, de la plus récente à la plus ancienne.
     *
     * @return list<array{id: string, origin: string, author: string, what: string, detail: string|null, at: string}>
     */
    public function timeline(QuoteRequestTrade $line): array
    {
        $request = $line->quoteRequest;
        $promoter = $this->userName($request->requestedByUser) ?? 'Le promoteur';
        $event = fn (string $id, string $origin, string $author, string $what, ?string $detail, ?CarbonInterface $at) => $at ? compact('id', 'origin', 'author', 'what', 'detail') + ['at' => $at->toIso8601String()] : null;
        $selected = $line->selectedBid;
        $cancelledByAdmin = AuditLog::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->getKey())->where('action', 'quote_request.cancelled')->exists();

        $events = collect([
            $event('publiee', 'promoteur', $promoter, 'a publié la demande de devis', $request->reference, $request->submitted_at),
            $event('commandee', 'promoteur', $promoter, 'a payé la commande des devis', $request->total_amount ? number_format($request->total_amount, 0, ',', ' ').' FCFA' : null, $request->ordered_at),
            $cancelledByAdmin ? null : $event('annulee', 'promoteur', $promoter, 'a annulé la demande', null, $request->cancelled_at),
            $selected ? $event('report', 'professionnel', $selected->contractor?->name ?? 'Le professionnel', 'a proposé un report de la visite', $selected->reschedule_reason, $selected->reschedule_requested_at) : null,
            $selected ? $event('visite', 'professionnel', $selected->contractor?->name ?? 'Le professionnel', 'a réalisé la visite', null, $selected->visited_at) : null,
        ])->filter();

        $bids = $line->bids->map(fn (QuoteBid $bid) => $event('proposition:'.$bid->id, 'professionnel', $bid->contractor?->name ?? 'Un professionnel', 'a proposé une visite', $bid->visit_date?->format('d/m/Y').($bid->visit_slot ? ' · '.$bid->visit_slot : ''), $bid->created_at))->filter();

        $versions = $line->versions->flatMap(fn (QuoteVersion $version) => [
            $event('envoi:'.$version->id, 'professionnel', $line->selectedBid?->contractor?->name ?? 'Le professionnel', 'a envoyé le devis', $version->number.' v'.$version->version, $version->sent_at),
            $event('reception:'.$version->id, 'promoteur', $promoter, 'a accusé réception du devis', $version->number.' v'.$version->version, $version->received_at),
        ])->filter();

        $logs = AuditLog::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->getKey())
            ->whereIn('action', array_keys(self::ADMIN_ACTIONS))->with('actor')->get();
        $audits = $logs
            ->filter(fn (AuditLog $log) => ($log->after_values['line_id'] ?? $line->getKey()) === $line->getKey())
            ->map(fn (AuditLog $log) => [
                'id' => 'audit:'.$log->id,
                'origin' => 'admin',
                'author' => $log->actor instanceof Admin ? $log->actor->fullName() : 'Administrateur',
                'what' => self::ADMIN_ACTIONS[$log->action],
                'detail' => $log->after_values['reason'] ?? $log->after_values['message'] ?? null,
                'at' => $log->created_at->toIso8601String(),
            ]);

        $ordered = collect([
            $events->firstWhere('id', 'publiee'),
            ...$bids,
            ...$events->reject(fn (array $e) => $e['id'] === 'publiee'),
            ...$versions,
            ...$audits,
        ])->filter()->values();

        // Tri stable : à la même seconde, l’ordre du parcours est gardé (la dernière étape en premier).
        return $ordered->sortBy('at')->reverse()->values()->all();
    }

    public function userName(?User $user): ?string
    {
        return $user ? (trim($user->first_name.' '.$user->last_name) ?: null) : null;
    }

    /**
     * Demande et ligne du même chantier (autres corps de métier de la demande).
     *
     * @return list<array{id: string, trade: string|null}>
     */
    public function siblings(QuoteRequestTrade $line): array
    {
        /** @var QuoteRequest $request */
        $request = $line->quoteRequest;

        return $request->trades->reject(fn (QuoteRequestTrade $other) => $other->is($line))
            ->map(fn (QuoteRequestTrade $other) => ['id' => $other->id, 'trade' => $other->trade?->label])->values()->all();
    }
}
