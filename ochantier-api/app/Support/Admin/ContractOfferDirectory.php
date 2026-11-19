<?php

namespace App\Support\Admin;

use App\Enums\OfferType;
use App\Enums\PricingVariable;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\ContractorBid;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\ContractorOffers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Offres contractuelles du Back Office (O’CHANTIER PRO) : prix fixé par le promoteur, réponses des professionnels
 * (conformes ou contre-propositions), choix du promoteur, acompte X et attribution. Le statut affiché est calculé à
 * partir de l’offre et de ses réponses ; le lien avec une demande de devis du même chantier est indiqué.
 */
class ContractOfferDirectory
{
    /**
     * Statuts affichés, dans l’ordre du parcours.
     */
    public const STATUSES = [
        'ouverte' => 'Ouverte',
        'en_comparaison' => 'En comparaison',
        'acompte_attendu' => 'Acompte en attente',
        'attribuee' => 'Chantier attribué',
        'expiree' => 'Expirée',
        'suspendue' => 'Suspendue',
        'cloturee' => 'Clôturée',
        'annulee' => 'Annulée',
    ];

    /**
     * Libellés des décisions du Back Office tracées dans le journal d’audit.
     */
    public const ADMIN_ACTIONS = [
        'contract_offer.extended' => 'a prolongé la date limite',
        'contract_offer.cancelled' => 'a annulé l’offre',
        'contract_offer.reminded' => 'a envoyé une relance',
    ];

    /**
     * Réponses envoyées (les brouillons restent privés au professionnel).
     */
    private const SENT = ['proposee', 'choisie', 'retenue', 'non_retenue', 'annulee'];

    /**
     * Calcul du statut affiché d’une offre. « :now » est remplacé par l’heure de l’application.
     */
    private const STATUS_SQL = "CASE WHEN offers.status = 'annulee' THEN 'annulee'"
        ." WHEN offers.status = 'cloturee' THEN 'cloturee'"
        ." WHEN offers.status = 'pourvue' OR offers.contractor_bid_id IS NOT NULL THEN 'attribuee'"
        ." WHEN offers.status = 'suspendue' THEN 'suspendue'"
        ." WHEN EXISTS (SELECT 1 FROM contractor_bids cb WHERE cb.offer_id = offers.id AND cb.status = 'choisie') THEN 'acompte_attendu'"
        ." WHEN offers.application_deadline < :now THEN 'expiree'"
        ." WHEN EXISTS (SELECT 1 FROM contractor_bids cb WHERE cb.offer_id = offers.id AND cb.status = 'proposee') THEN 'en_comparaison'"
        ." ELSE 'ouverte' END";

    public function __construct(private ContractorOffers $offers, private PricingRules $pricing) {}

    /**
     * Statut affiché en SQL, la date limite comparée à l’heure de l’application.
     */
    public static function statusSql(): string
    {
        return str_replace(':now', DB::getPdo()->quote(now()->toIso8601String()).'::timestamptz', self::STATUS_SQL);
    }

    /**
     * Offres filtrées. Filtres : search (référence, chantier, promoteur), trade (code d’un métier du devis), city_id,
     * status (statuts affichés).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Offer>
     */
    public function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $sent = fn (Builder $query) => $query->whereIn('status', self::SENT);

        return Offer::query()
            ->join('sites', 'sites.id', '=', 'offers.site_id')
            ->where('offers.offer_type', OfferType::Contractuelle->value)
            ->whereNotIn('offers.status', ['brouillon', 'soumise', 'en_controle', 'a_corriger'])
            ->select('offers.*')
            ->selectRaw(self::statusSql().' as display_status')
            ->with(['site.commune.city', 'site.promoterProfile.user', 'contractorBid.contractor'])
            ->withCount(['contractorBids as responses_count' => $sent, 'contractorBids as counter_proposals_count' => fn (Builder $query) => $sent($query)->where('response_mode', 'contre_proposition')])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('offers.reference', 'ilike', '%'.$search.'%')
                ->orWhere('sites.label', 'ilike', '%'.$search.'%')
                ->orWhereExists(fn (QueryBuilder $sub) => $sub->select(DB::raw(1))->from('promoter_profiles')->join('users', 'users.id', '=', 'promoter_profiles.user_id')
                    ->whereColumn('promoter_profiles.id', 'sites.promoter_profile_id')
                    ->whereRaw("concat_ws(' ', users.first_name, users.last_name) ilike ?", ['%'.$search.'%']))))
            ->when($filters['trade'] ?? null, fn (Builder $query, string $code) => $query->whereExists(fn (QueryBuilder $sub) => $sub->select(DB::raw(1))->from('quote_requests')
                ->join('quote_request_trades', 'quote_request_trades.quote_request_id', '=', 'quote_requests.id')
                ->join('trades', 'trades.id', '=', 'quote_request_trades.trade_id')
                ->whereColumn('quote_requests.site_id', 'offers.site_id')->where('trades.code', $code)))
            ->when($filters['city_id'] ?? null, fn (Builder $query, string $city) => $query->whereIn('sites.commune_id', DB::table('communes')->where('city_id', $city)->select('id')))
            ->when($filters['status'] ?? [], fn (Builder $query, array $statuses) => $query->whereRaw(self::statusSql().' IN ('.implode(',', array_fill(0, count($statuses), '?')).')', $statuses))
            ->orderByDesc('offers.published_at')
            ->orderBy('offers.id');
    }

    /**
     * Nombre d’offres par statut affiché.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $status = self::statusSql();
        $counts = DB::table('offers')->where('offer_type', OfferType::Contractuelle->value)->whereNotIn('status', ['brouillon', 'soumise', 'en_controle', 'a_corriger'])
            ->selectRaw($status.' as display_status, count(*) as total')->groupByRaw($status)->pluck('total', 'display_status');

        return collect(self::STATUSES)->mapWithKeys(fn (string $label, string $key) => [$key => (int) ($counts[$key] ?? 0)])->all();
    }

    /**
     * Options des filtres : métiers des devis liés et villes des chantiers.
     *
     * @return array{trades: mixed, cities: mixed}
     */
    public function options(): array
    {
        $sites = DB::table('offers')->where('offer_type', OfferType::Contractuelle->value)->select('site_id');

        return [
            'trades' => DB::table('trades')->whereIn('id', DB::table('quote_request_trades')->join('quote_requests', 'quote_requests.id', '=', 'quote_request_trades.quote_request_id')->whereIn('quote_requests.site_id', $sites)->select('quote_request_trades.trade_id'))
                ->orderBy('sort_order')->orderBy('label')->get(['code', 'label']),
            'cities' => City::query()->whereIn('id', DB::table('sites')->join('communes', 'communes.id', '=', 'sites.commune_id')->whereIn('sites.id', $sites)->select('communes.city_id'))->orderBy('name')->get(['id', 'name']),
        ];
    }

    public function find(string $id): Offer
    {
        abort_unless(str($id)->isUuid(), 404);

        return $this->query([])->where('offers.id', $id)
            ->with(['contractorBids' => fn ($query) => $query->whereIn('status', self::SENT)->with('contractor')->orderBy('sent_at'), 'needs.missions.paymentSchedules', 'milestones'])
            ->firstOrFail();
    }

    public function status(Offer $offer): string
    {
        return (string) DB::table('offers')->where('id', $offer->getKey())->value(DB::raw(self::statusSql()));
    }

    /**
     * Demande de devis du chantier dont l’offre est issue (devis reçu) et professionnels auteurs des devis.
     *
     * @return array{id: string, reference: string, line_ids: list<string>, contractor_ids: list<string>}|null
     */
    public function quoteOrigin(Offer $offer): ?array
    {
        $request = QuoteRequest::query()->where('site_id', $offer->site_id)->where('status', '!=', 'annule')
            ->where('submitted_at', '<=', $offer->published_at ?? now())->latest('submitted_at')
            ->with('trades.selectedBid')->first();

        return $request ? [
            'id' => $request->id,
            'reference' => $request->reference,
            'line_ids' => $request->trades->pluck('id')->values()->all(),
            'contractor_ids' => $request->trades->map(fn ($line) => $line->selectedBid?->contractor_id)->filter()->unique()->values()->all(),
        ] : null;
    }

    /**
     * Identifiants des réponses montrées au promoteur (les meilleures selon le classement).
     *
     * @return list<string>
     */
    public function shownBidIds(Offer $offer): array
    {
        return $offer->status->value === 'publiee' ? $this->offers->bestBids($offer)->modelKeys() : [];
    }

    /**
     * Prix promoteur d’une réponse : montant net, commission Y, total, acompte X et solde.
     *
     * @return array{workers: int, commission: int, total: int, deposit: int, balance: int}
     */
    public function prices(ContractorBid $bid): array
    {
        return $this->offers->prices($bid);
    }

    /**
     * Taux de la commission Y et de l’acompte X en vigueur.
     *
     * @return array{commission_percent: float, deposit_percent: float}
     */
    public function rates(): array
    {
        return ['commission_percent' => (float) $this->pricing->value(PricingVariable::Y), 'deposit_percent' => (float) $this->pricing->value(PricingVariable::X)];
    }

    /**
     * Chronologie : changements de statut de l’offre, réponses, choix du promoteur et décisions du Back Office.
     *
     * @return list<array{id: string, origin: string, author: string, what: string, detail: string|null, at: string}>
     */
    public function timeline(Offer $offer): array
    {
        $admin = (new Admin)->getMorphClass();
        $histories = $offer->statusHistories()->where('actor_type', '!=', $admin)->get();
        $users = User::query()->whereIn('id', $histories->pluck('actor_id')->merge($offer->contractorBids->pluck('chosen_by_user_id'))->filter()->unique())->get()->keyBy('id');
        $name = fn (?User $user) => $user ? (trim($user->first_name.' '.$user->last_name) ?: 'Le promoteur') : 'Le promoteur';
        $labels = ['publiee' => 'a publié l’offre', 'pourvue' => 'a payé l’acompte', 'annulee' => 'a annulé l’offre', 'cloturee' => 'a clôturé l’offre', 'suspendue' => 'a suspendu l’offre'];

        $events = collect();
        $later = collect();

        foreach ($histories as $history) {
            /** @var OfferStatusHistory $history */
            ($history->to_status?->value === 'publiee' ? $events : $later)->push(['id' => 'statut:'.$history->id, 'origin' => $history->actor_type === (new User)->getMorphClass() ? 'promoteur' : 'systeme', 'author' => $name($users->get($history->actor_id)),
                'what' => $labels[$history->to_status?->value] ?? 'a changé le statut', 'detail' => $history->reason, 'at' => $history->created_at->toIso8601String()]);
        }

        foreach ($offer->contractorBids as $bid) {
            $events->push(['id' => 'reponse:'.$bid->id, 'origin' => 'professionnel', 'author' => $bid->contractor?->name ?? 'Un professionnel',
                'what' => $bid->response_mode?->value === 'conforme' ? 'a accepté les conditions' : 'a fait une contre-proposition', 'detail' => number_format((int) $bid->amount, 0, ',', ' ').' FCFA · '.$bid->duration_days.' jours', 'at' => ($bid->sent_at ?? $bid->created_at)->toIso8601String()]);

            if ($bid->chosen_at) {
                $events->push(['id' => 'choix:'.$bid->id, 'origin' => 'promoteur', 'author' => $name($users->get($bid->chosen_by_user_id)), 'what' => 'a choisi l’offre de '.($bid->contractor?->name ?? 'un professionnel'), 'detail' => null, 'at' => $bid->chosen_at->toIso8601String()]);
            }
        }

        $audits = AuditLog::query()->where('subject_type', $offer->getMorphClass())->where('subject_id', $offer->getKey())->whereIn('action', array_keys(self::ADMIN_ACTIONS))->with('actor')->get()
            ->map(fn (AuditLog $log) => ['id' => 'audit:'.$log->id, 'origin' => 'admin', 'author' => $log->actor instanceof Admin ? $log->actor->fullName() : 'Administrateur',
                'what' => self::ADMIN_ACTIONS[$log->action], 'detail' => $log->after_values['reason'] ?? $log->after_values['message'] ?? null, 'at' => $log->created_at->toIso8601String()]);

        // Tri stable : à la même seconde, l’ordre du parcours est gardé (la dernière étape en premier).
        return $events->concat($later)->concat($audits)->sortBy('at')->reverse()->values()->all();
    }

    public function userName(?User $user): ?string
    {
        return $user ? (trim($user->first_name.' '.$user->last_name) ?: null) : null;
    }
}
