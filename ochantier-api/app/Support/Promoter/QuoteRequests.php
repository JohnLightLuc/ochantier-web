<?php

namespace App\Support\Promoter;

use App\Enums\QuoteBidStatus;
use App\Enums\QuoteRequestStatus;
use App\Enums\QuoteRequestTradeStatus;
use App\Enums\QuoteVersionStatus;
use App\Enums\QuoteVisitStatus;
use App\Enums\SiteStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Contractor;
use App\Models\DocumentType;
use App\Models\PaymentTransaction;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestTrade;
use App\Models\QuoteVersion;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Support\Contractor\ContractorNotifier;
use App\Support\Documents\DocumentStore;
use App\Support\Documents\SimplePdf;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demande de devis d’une offre contractuelle (« Avez-vous un devis ? » → Non).
 *
 * « Rechercher un technicien » crée la demande (un corps de métier par ligne), passe le chantier au statut « devis »
 * et prévient les entrepreneurs du métier et de la ville. Chacun peut envoyer une proposition (frais de visite ou
 * d’étude, plus les frais de service O’CHANTIER, date et créneau de visite) jusqu’à la date limite : le promoteur
 * voit les BEST_BIDS meilleures par métier (note décroissante, puis prix croissant), en choisit une par métier et
 * paie la « Commande des devis » en une fois.
 *
 * Chaque proposition commandée devient une mission de visite ; l’entrepreneur peut proposer un report, que le
 * promoteur accepte ou refuse. Après la visite, il rédige le devis : chaque version envoyée est ajoutée aux
 * documents du chantier (PDF) et le promoteur en accuse réception. Quand chaque métier a reçu son devis, le chantier
 * revient au brouillon avec « Oui » répondu à « Avez-vous un devis ? » : il reste à définir l’offre financière.
 */
class QuoteRequests
{
    /**
     * Offres affichées par corps de métier, par défaut (réglage pro.compared_offers).
     */
    public const BEST_BIDS = 3;

    /**
     * Offres de professionnels montrées au promoteur, par métier d’une demande de devis ou par offre contractuelle.
     */
    public static function comparedOffers(): int
    {
        return max(1, (int) (Setting::query()->whereKey('pro.compared_offers')->value('value') ?? self::BEST_BIDS));
    }

    /**
     * Durée de validité d’un devis réalisé par défaut (jours).
     */
    public const VALIDITY_DAYS = 30;

    /**
     * Créneaux de visite proposés.
     */
    public const SLOTS = ['08:00 – 10:00', '10:00 – 12:00', '12:00 – 14:00', '14:00 – 16:00', '16:00 – 18:00'];

    /**
     * Durées de visite proposées.
     */
    public const DURATIONS = ['1 h', '2 h', '3 h', 'Demi-journée', 'Journée'];

    /**
     * Frais de service O’CHANTIER par défaut (%), si le paramètre quotes.service_fee_percent manque.
     */
    public const DEFAULT_SERVICE_FEE = 10;

    /**
     * Taux de TVA proposé (%) pour la première version d’un devis.
     */
    public const DEFAULT_VAT_RATE = 18;

    public function __construct(private OfferDraft $drafts, private DocumentStore $documents, private MediaStore $media) {}

    /**
     * « Rechercher un technicien » : crée la demande à partir du brouillon, lance la recherche et prévient les
     * entrepreneurs concernés.
     */
    public function submit(Site $site, User $author): QuoteRequest
    {
        if ($site->status !== SiteStatus::Brouillon) {
            throw ValidationException::withMessages(['site' => 'Ce chantier n’est plus un brouillon.']);
        }

        if ($errors = $this->drafts->publicationErrors($site)) {
            throw ValidationException::withMessages(['publication' => array_column($errors, 'label')]);
        }

        $request = DB::transaction(function () use ($site, $author): QuoteRequest {
            $request = $site->quoteRequests()->create([
                'reference' => sprintf('DV-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('quote_request_reference_seq')")),
                'status' => QuoteRequestStatus::Recherche,
                'notes' => $site->offer_draft['contract']['quote_request']['notes'] ?? null,
                'requested_by_user_id' => $author->getKey(),
                'submitted_at' => now(),
                'response_deadline' => now()->addHours((int) (Setting::query()->whereKey('quotes.response_hours')->value('value') ?? 72)),
            ]);

            foreach ($this->drafts->quoteTrades($site) as $trade) {
                $request->trades()->create(['trade_id' => $trade->getKey(), 'status' => QuoteRequestTradeStatus::Recherche]);
            }

            $this->moveSite($site, SiteStatus::Devis, $author);

            return $request;
        });

        app(ContractorNotifier::class)->newQuoteRequest($request);

        return $request;
    }

    /**
     * Annule une demande encore en recherche (aucun paiement) : le chantier revient au brouillon et les
     * propositions ne sont pas retenues. Auteur : le promoteur, ou un administrateur (avec son motif).
     */
    public function cancel(Site $site, Model $author, ?string $reason = null): void
    {
        $request = $this->current($site);

        if ($request?->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_request' => 'Seule une demande de devis en recherche peut être annulée.']);
        }

        $bids = QuoteBid::query()->with('contractor.user')->whereHas('quoteRequestTrade', fn ($query) => $query->where('quote_request_id', $request->getKey()))
            ->where('status', QuoteBidStatus::Proposee->value)->get();

        DB::transaction(function () use ($site, $request, $author, $bids): void {
            $request->update(['status' => QuoteRequestStatus::Annule, 'cancelled_at' => now()]);
            QuoteBid::query()->whereKey($bids->modelKeys())->update(['status' => QuoteBidStatus::NonRetenue->value]);
            $this->moveSite($site, SiteStatus::Brouillon, $author);
        });

        foreach ($bids as $bid) {
            app(ContractorNotifier::class)->proposalEvent($bid, 'proposal.not_retained', 'Demande de devis annulée', $site->label.($reason ? ' : demande annulée par O’CHANTIER. '.$reason : ' : le promoteur a annulé sa demande de devis.'));
        }
    }

    /**
     * Dernière demande non annulée du chantier.
     */
    public function current(Site $site): ?QuoteRequest
    {
        return $site->quoteRequests()->where('status', '!=', QuoteRequestStatus::Annule->value)->latest('submitted_at')->first();
    }

    /**
     * Le chantier a une commande de devis payée : il ne peut plus être supprimé.
     */
    public function hasOrder(Site $site): bool
    {
        return $site->quoteRequests()->whereIn('status', [QuoteRequestStatus::Commande->value, QuoteRequestStatus::Recu->value])->exists();
    }

    /**
     * Frais de service O’CHANTIER appliqués aux nouvelles propositions (%).
     */
    public function serviceFeePercent(): float
    {
        return (float) (Setting::query()->whereKey('quotes.service_fee_percent')->value('value') ?? self::DEFAULT_SERVICE_FEE);
    }

    /**
     * Prix d’une proposition : frais de l’entrepreneur, frais de service et total payé par le promoteur.
     *
     * @return array{fee: int, service_fee_percent: float, service_fee: int, total: int}
     */
    public function prices(int $fee, ?float $percent = null): array
    {
        $percent ??= $this->serviceFeePercent();
        $service = (int) round($fee * $percent / 100);

        return ['fee' => $fee, 'service_fee_percent' => $percent, 'service_fee' => $service, 'total' => $fee + $service];
    }

    /**
     * Proposition d’un entrepreneur pour un corps de métier encore en recherche (une par entrepreneur). La distance
     * est calculée entre l’entrepreneur et le chantier ; les frais de service sont figés à l’envoi.
     */
    public function addBid(QuoteRequestTrade $line, Contractor $contractor, int $fee, CarbonImmutable $visitDate, string $slot, ?string $duration = null, ?string $comment = null): QuoteBid
    {
        if ($line->status !== QuoteRequestTradeStatus::Recherche || $line->quoteRequest->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_request' => 'Ce corps de métier n’attend plus de proposition.']);
        }

        if ($line->quoteRequest->response_deadline?->isPast()) {
            throw ValidationException::withMessages(['quote_request' => 'Le délai de réponse est expiré.']);
        }

        $existing = $line->bids()->where('contractor_id', $contractor->getKey())->first();

        if ($existing) {
            throw ValidationException::withMessages(['quote_request' => $existing->status === QuoteBidStatus::Retiree
                ? 'Vous avez retiré votre proposition pour cette demande.'
                : 'Vous avez déjà envoyé une proposition pour cette demande.']);
        }

        $distance = DB::scalar('SELECT ST_Distance(c.location, s.location) / 1000 FROM contractors c, sites s WHERE c.id = ? AND s.id = ?', [$contractor->getKey(), $line->quoteRequest->site_id]);
        $prices = $this->prices($fee);

        $bid = $line->bids()->create([
            'contractor_id' => $contractor->getKey(),
            'amount' => $prices['total'],
            'fee_amount' => $prices['fee'],
            'service_fee_percent' => $prices['service_fee_percent'],
            'service_fee_amount' => $prices['service_fee'],
            'visit_date' => $visitDate->toDateString(),
            'visit_slot' => $slot,
            'visit_duration' => $duration,
            'comment' => $comment,
            'distance_km' => $distance !== null ? round((float) $distance, 1) : null,
            'status' => QuoteBidStatus::Proposee,
        ]);

        DB::afterCommit(fn () => $this->notifyOffersReceived($line->quoteRequest));

        return $bid;
    }

    /**
     * « Offres de devis reçues » : prévient le promoteur, une seule fois par demande, dès que chaque corps de métier
     * a au moins une offre (il peut alors choisir une offre par métier et commander les devis).
     */
    private function notifyOffersReceived(QuoteRequest $request): void
    {
        if ($request->status !== QuoteRequestStatus::Recherche || ($this->action($request)['value'] ?? null) !== 'choisir_devis') {
            return;
        }

        $site = $request->site;
        $trades = $request->trades()->count();

        app(PromoterNotifier::class)->notify($site->promoterProfile, 'quote.offers_received', 'Offres de devis reçues',
            $site->label.' : '.($trades > 1 ? 'chaque corps de métier a reçu au moins une offre' : 'une offre de devis est arrivée').'. Choisissez une offre par métier pour commander les devis.',
            $site, ['type' => 'quote_request', 'id' => $site->id], $request, 'quote.offers_received:'.$request->id);
    }

    /**
     * Meilleures propositions d’un corps de métier : note de l’entrepreneur décroissante, puis prix croissant.
     *
     * @return Collection<int, QuoteBid>
     */
    public function bestBids(QuoteRequestTrade $line): Collection
    {
        return $line->bids()
            ->with(['contractor.logoMedia', 'worker.photoMedia', 'worker.workerTrades'])
            ->where('quote_bids.status', QuoteBidStatus::Proposee->value)
            ->leftJoin('contractors', 'contractors.id', '=', 'quote_bids.contractor_id')
            ->leftJoin('workers', 'workers.id', '=', 'quote_bids.worker_id')
            ->orderByRaw('coalesce(contractors.rating_avg, workers.rating_avg) DESC NULLS LAST')
            ->orderBy('quote_bids.amount')
            ->orderBy('quote_bids.created_at')
            ->limit(self::comparedOffers())
            ->get(['quote_bids.*']);
    }

    /**
     * Ce que règle la « Commande des devis » : une offre choisie parmi les meilleures de chaque corps de métier.
     *
     * @param  list<string>  $bidIds
     * @return array{kind: string, label: string, amount: int, site: Site, proposal: null, schedules: Collection<int, never>, lines: list<array{label: string, amount: int}>, done_text: string, quote_request: QuoteRequest, bids: Collection<int, QuoteBid>}
     */
    public function payable(User $user, array $bidIds): array
    {
        /** @var Collection<int, QuoteBid> $bids */
        $bids = QuoteBid::query()->with(['quoteRequestTrade.quoteRequest.site', 'quoteRequestTrade.trade'])->whereKey($bidIds)->get();
        $request = $bids->first()?->quoteRequestTrade->quoteRequest;

        if (! $request || $bids->count() !== count(array_unique($bidIds)) || $bids->pluck('quoteRequestTrade.quote_request_id')->unique()->count() > 1
            || ! app(PromoterAccess::class)->scopeSites(Site::query(), $user)->whereKey($request->site_id)->exists()) {
            throw ValidationException::withMessages(['quote_bid_ids' => 'Offres de devis introuvables.']);
        }

        if ($request->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_bid_ids' => 'Ces devis sont déjà commandés.']);
        }

        $lines = $request->trades()->with('trade')->get();

        foreach ($lines as $line) {
            $chosen = $bids->where('quote_request_trade_id', $line->getKey());

            if ($chosen->count() !== 1) {
                throw ValidationException::withMessages(['quote_bid_ids' => 'Choisissez une offre pour « '.$line->trade->label.' ».']);
            }

            if (! $this->bestBids($line)->contains('id', $chosen->first()->getKey())) {
                throw ValidationException::withMessages(['quote_bid_ids' => 'Cette offre n’est plus proposée pour « '.$line->trade->label.' ».']);
            }
        }

        $site = $request->site;
        $count = $lines->count();

        return [
            'kind' => 'commande_devis',
            'label' => 'Commande des devis · '.$count.' métier'.($count > 1 ? 's' : ''),
            'amount' => (int) $bids->sum('amount'),
            'site' => $site,
            'proposal' => null,
            'schedules' => collect(),
            'lines' => $bids->sortBy(fn (QuoteBid $bid) => $bid->quoteRequestTrade->trade->sort_order)
                ->map(fn (QuoteBid $bid) => ['label' => 'Devis '.mb_strtolower($bid->quoteRequestTrade->trade->label), 'amount' => $bid->amount])
                ->values()->all(),
            'done_text' => 'Vos devis sont commandés. Chaque devis sera ajouté au chantier dès sa réalisation.',
            'quote_request' => $request,
            'bids' => $bids,
        ];
    }

    /**
     * Paiement réussi : les offres choisies sont commandées (mission de visite planifiée), les autres ne sont pas
     * retenues. Les entrepreneurs en sont prévenus.
     *
     * @param  Collection<int, QuoteBid>  $bids
     */
    public function order(QuoteRequest $request, Collection $bids, PaymentTransaction $transaction, User $author): void
    {
        $request = QuoteRequest::query()->lockForUpdate()->findOrFail($request->getKey());

        if ($request->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_bid_ids' => 'Ces devis sont déjà commandés.']);
        }

        $notRetained = collect();

        foreach ($request->trades as $line) {
            $chosen = $bids->firstWhere('quote_request_trade_id', $line->getKey());
            $others = $line->bids()->whereKeyNot($chosen->getKey())->where('status', QuoteBidStatus::Proposee->value);
            $notRetained = $notRetained->concat($others->get());
            $others->update(['status' => QuoteBidStatus::NonRetenue->value]);
            $chosen->update(['status' => QuoteBidStatus::Choisie, 'visit_status' => $chosen->contractor_id ? QuoteVisitStatus::Planifiee : null]);
            $line->update(['status' => QuoteRequestTradeStatus::Commande, 'selected_bid_id' => $chosen->getKey()]);
        }

        $request->update([
            'status' => QuoteRequestStatus::Commande,
            'total_amount' => (int) $bids->sum('amount'),
            'payment_transaction_id' => $transaction->getKey(),
            'ordered_at' => now(),
        ]);

        DB::afterCommit(function () use ($bids, $notRetained, $request): void {
            $notifier = app(ContractorNotifier::class);
            $title = $request->site->label;

            foreach ($bids as $bid) {
                $notifier->proposalEvent($bid, 'proposal.ordered', 'Proposition acceptée · mission confirmée', $title.' : visite le '.$bid->visit_date->format('d/m/Y').($bid->visit_slot ? ', créneau '.$bid->visit_slot : '').'.');
            }

            foreach ($notRetained as $bid) {
                $notifier->proposalEvent($bid, 'proposal.not_retained', 'Proposition non retenue', $title.' : le promoteur a retenu une autre proposition.');
            }
        });
    }

    /**
     * Retrait d’une proposition encore en attente de la décision du promoteur.
     */
    public function withdraw(QuoteBid $bid): QuoteBid
    {
        if ($bid->status !== QuoteBidStatus::Proposee || $bid->quoteRequestTrade->quoteRequest->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['proposal' => 'Seule une proposition en attente peut être retirée.']);
        }

        $bid->update(['status' => QuoteBidStatus::Retiree, 'withdrawn_at' => now()]);

        return $bid;
    }

    /**
     * « Marquer la visite réalisée » : le devis peut ensuite être rédigé.
     */
    public function markVisited(QuoteBid $bid): QuoteBid
    {
        $this->ensureMission($bid);

        if ($bid->visit_status !== QuoteVisitStatus::Planifiee) {
            throw ValidationException::withMessages(['visit' => $bid->visit_status === QuoteVisitStatus::Realisee ? 'La visite est déjà réalisée.' : 'Un report est en attente de la décision du promoteur.']);
        }

        $bid->update(['visit_status' => QuoteVisitStatus::Realisee, 'visited_at' => now()]);

        return $bid;
    }

    /**
     * « Signaler un empêchement » : nouvelle date et créneau proposés au promoteur, avec le motif.
     */
    public function requestReschedule(QuoteBid $bid, CarbonImmutable $date, string $slot, string $reason): QuoteBid
    {
        $this->ensureMission($bid);

        if ($bid->visit_status !== QuoteVisitStatus::Planifiee) {
            throw ValidationException::withMessages(['visit' => $bid->visit_status === QuoteVisitStatus::Realisee ? 'La visite est déjà réalisée.' : 'Un report est déjà en attente de la décision du promoteur.']);
        }

        $bid->update([
            'visit_status' => QuoteVisitStatus::ReportPropose,
            'reschedule_date' => $date->toDateString(),
            'reschedule_slot' => $slot,
            'reschedule_reason' => $reason,
            'reschedule_requested_at' => now(),
        ]);

        $site = $bid->quoteRequestTrade->quoteRequest->site;
        app(PromoterNotifier::class)->notify($site->promoterProfile, 'quote.reschedule_requested', 'Report de visite proposé',
            $bid->quoteRequestTrade->trade->label.' : '.$reason.' Nouvelle date proposée : '.$date->format('d/m/Y').', '.$slot.'.',
            $site, ['type' => 'quote_request', 'id' => $site->id], $bid);

        return $bid;
    }

    /**
     * Décision du promoteur sur le report proposé : accepté, la visite prend la nouvelle date ; refusé, elle garde la
     * date convenue.
     */
    public function decideReschedule(QuoteBid $bid, bool $accepted): QuoteBid
    {
        if ($bid->visit_status !== QuoteVisitStatus::ReportPropose) {
            throw ValidationException::withMessages(['visit' => 'Aucun report de visite n’est en attente.']);
        }

        $date = $bid->reschedule_date;
        $bid->update([
            ...($accepted ? ['visit_date' => $bid->reschedule_date, 'visit_slot' => $bid->reschedule_slot] : []),
            'visit_status' => QuoteVisitStatus::Planifiee,
            'reschedule_date' => null,
            'reschedule_slot' => null,
            'reschedule_reason' => null,
            'reschedule_requested_at' => null,
        ]);

        app(ContractorNotifier::class)->proposalEvent($bid, $accepted ? 'visit.reschedule_accepted' : 'visit.reschedule_refused',
            $accepted ? 'Report validé' : 'Report refusé',
            $accepted ? 'Nouvelle date de visite : '.$date->format('d/m/Y').', '.$bid->visit_slot.'.' : 'La visite reste prévue le '.$bid->visit_date->format('d/m/Y').', '.$bid->visit_slot.'.');

        return $bid->refresh();
    }

    /**
     * Brouillon de devis : première version, ou version corrigée reprenant la dernière envoyée.
     */
    public function draftVersion(QuoteBid $bid): QuoteVersion
    {
        $this->ensureMission($bid);

        if ($bid->visit_status !== QuoteVisitStatus::Realisee) {
            throw ValidationException::withMessages(['quote' => 'Marquez d’abord la visite réalisée.']);
        }

        $line = $bid->quoteRequestTrade;
        $last = $line->versions()->orderByDesc('version')->first();

        if ($last?->status === QuoteVersionStatus::Brouillon) {
            throw ValidationException::withMessages(['quote' => 'Un brouillon de devis est déjà en cours (v'.$last->version.').']);
        }

        return $line->versions()->create([
            'quote_bid_id' => $bid->getKey(),
            'number' => $last?->number ?? sprintf('DE-%s-%05d', now()->format('Y'), DB::scalar("SELECT nextval('site_quote_number_seq')")),
            'version' => ($last?->version ?? 0) + 1,
            'status' => QuoteVersionStatus::Brouillon,
            'items' => $last?->items ?? [],
            'vat_applied' => $last?->vat_applied ?? false,
            'vat_rate' => $last?->vat_rate ?? self::DEFAULT_VAT_RATE,
            'total_ht' => $last?->total_ht ?? 0,
            'vat_amount' => $last?->vat_amount ?? 0,
            'total_ttc' => $last?->total_ttc ?? 0,
            'works_duration' => $last?->works_duration,
            'conditions' => $last?->conditions ?? 'Acompte à la commande, solde à la réception des travaux.',
            'validity_days' => $last?->validity_days ?? self::VALIDITY_DAYS,
            'observations' => $last?->observations,
        ]);
    }

    /**
     * Enregistre les champs envoyés du brouillon et recalcule les totaux.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateVersion(QuoteVersion $version, array $data): QuoteVersion
    {
        if ($version->status !== QuoteVersionStatus::Brouillon) {
            throw ValidationException::withMessages(['quote' => 'Cette version est envoyée : créez une version corrigée.']);
        }

        if (array_key_exists('items', $data)) {
            $data['items'] = array_map(fn (array $item) => [
                'designation' => trim((string) $item['designation']),
                'unit' => $item['unit'] ?? 'u',
                'quantity' => (float) $item['quantity'],
                'unit_price' => (int) $item['unit_price'],
                'total' => (int) round((float) $item['quantity'] * (int) $item['unit_price']),
            ], array_values($data['items']));
        }

        $version->fill($data);
        $version->fill($this->totals($version->items ?? [], (bool) $version->vat_applied, (float) $version->vat_rate));
        $version->save();

        return $version;
    }

    /**
     * Envoie la version signée : PDF ajouté aux documents du chantier, devis du corps de métier mis à jour, promoteur
     * prévenu. Le premier devis de chaque métier fait avancer la demande.
     */
    public function sendVersion(QuoteVersion $version, Model $author): QuoteVersion
    {
        if ($version->status !== QuoteVersionStatus::Brouillon) {
            throw ValidationException::withMessages(['quote' => 'Cette version est déjà envoyée.']);
        }

        $errors = [];

        if (($version->items ?? []) === []) {
            $errors['items'] = 'Ajoutez au moins une ligne de travaux.';
        }

        foreach ($version->items ?? [] as $index => $item) {
            if (blank($item['designation']) || $item['quantity'] <= 0 || $item['unit_price'] <= 0) {
                $errors['items.'.$index] = 'Ligne '.($index + 1).' : désignation, quantité et prix unitaire obligatoires.';
            }
        }

        if (blank($version->works_duration)) {
            $errors['works_duration'] = 'Indiquez la durée des travaux.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($version, $author): QuoteVersion {
            $line = $version->quoteRequestTrade;
            $bid = $version->quoteBid;
            $request = $line->quoteRequest;
            $site = $request->site;

            $version->forceFill(['status' => QuoteVersionStatus::Envoye, 'signed_at' => now(), 'sent_at' => now()])->save();
            $document = $this->attachPdf($site, $bid, $line, $version, $author);
            $version->forceFill(['document_id' => $document->getKey()])->save();

            $first = $line->status !== QuoteRequestTradeStatus::Realise;
            $line->forceFill([
                'quote_number' => $version->number,
                'quote_items' => $version->items,
                'quote_total_amount' => $version->total_ttc,
                'visited_on' => ($bid->visited_at ?? $bid->visit_date)->toDateString(),
                'issued_on' => today()->toDateString(),
                'valid_until' => today()->addDays($version->validity_days)->toDateString(),
                'quote_document_id' => $document->getKey(),
                'status' => QuoteRequestTradeStatus::Realise,
                'completed_at' => $line->completed_at ?? now(),
            ])->save();

            if ($first && ! $request->trades()->where('status', '!=', QuoteRequestTradeStatus::Realise->value)->exists()) {
                $request->update(['status' => QuoteRequestStatus::Recu, 'received_at' => now()]);
                $draft = $site->offer_draft ?? [];
                $draft['type'] = OfferDraft::CONTRACT;
                $draft['contract'] = [...($draft['contract'] ?? []), 'has_quote' => true];
                $site->forceFill(['offer_draft' => $draft]);
                $this->moveSite($site, SiteStatus::Brouillon, $author);
            }

            DB::afterCommit(fn () => app(PromoterNotifier::class)->notify($site->promoterProfile, 'quote.sent', 'Devis reçu',
                'Devis '.$version->number.' v'.$version->version.' ('.mb_strtolower($line->trade->label).') : '.$this->money($version->total_ttc).'. Il est disponible dans les documents du chantier.',
                $site, ['type' => 'quote_request', 'id' => $site->id], $version));

            return $version->refresh();
        });
    }

    /**
     * Accusé de réception du promoteur sur une version envoyée.
     */
    public function acknowledge(QuoteVersion $version): QuoteVersion
    {
        if ($version->status !== QuoteVersionStatus::Envoye) {
            throw ValidationException::withMessages(['quote' => $version->status === QuoteVersionStatus::Recu ? 'La réception de ce devis est déjà confirmée.' : 'Ce devis n’est pas encore envoyé.']);
        }

        $version->update(['status' => QuoteVersionStatus::Recu, 'received_at' => now()]);
        app(ContractorNotifier::class)->proposalEvent($version->quoteBid, 'quote.received', 'Accusé de réception', 'Le promoteur a reçu le devis '.$version->number.' v'.$version->version.'.');

        return $version;
    }

    /**
     * Devis réalisé d’un coup (simulation) : visite marquée réalisée, première version rédigée et envoyée.
     *
     * @param  list<array{designation: string, unit_price: int, quantity: float|int, unit?: string}>  $items
     */
    public function complete(QuoteRequestTrade $line, array $items, CarbonImmutable $visitedOn): QuoteRequestTrade
    {
        if ($line->status !== QuoteRequestTradeStatus::Commande) {
            throw ValidationException::withMessages(['quote_request' => 'Ce devis n’est pas commandé ou est déjà réalisé.']);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Le devis doit comporter au moins une ligne.']);
        }

        $bid = $line->selectedBid;
        $bid->update(['visit_status' => QuoteVisitStatus::Realisee, 'visited_at' => $visitedOn]);
        $version = $this->updateVersion($this->draftVersion($bid), ['items' => $items, 'works_duration' => '15 jours']);
        $this->sendVersion($version, $bid->contractor ?? $bid->worker);

        return $line->refresh();
    }

    /**
     * Action de la carte du chantier : « Choisir les offres de devis » dès que chaque corps de métier a reçu une
     * offre, « Définir l’offre financière » une fois les devis reçus.
     *
     * @return array{value: string, label: string}|null
     */
    public function action(QuoteRequest $request): ?array
    {
        return match (true) {
            $request->status === QuoteRequestStatus::Recherche && $request->trades()->whereDoesntHave('bids', fn ($query) => $query->where('status', QuoteBidStatus::Proposee->value))->doesntExist() => ['value' => 'choisir_devis', 'label' => 'Choisir les offres de devis'],
            $request->status === QuoteRequestStatus::Recu && $request->site->status === SiteStatus::Brouillon => ['value' => 'definir_offre', 'label' => 'Définir l’offre financière'],
            default => null,
        };
    }

    /**
     * « Offres de devis », « Devis commandé », « Devis reçu » du détail chantier.
     *
     * @return array<string, mixed>
     */
    public function summary(QuoteRequest $request): array
    {
        $lines = $request->trades()->with(['trade', 'selectedBid.contractor.logoMedia', 'selectedBid.worker.photoMedia', 'selectedBid.worker.workerTrades', 'quoteDocument.documentType', 'quoteDocument.files', 'versions.document.documentType', 'versions.document.files'])->get()
            ->sortBy(fn (QuoteRequestTrade $line) => $line->trade->sort_order)->values();
        $searching = $request->status === QuoteRequestStatus::Recherche;

        $trades = $lines->map(function (QuoteRequestTrade $line) use ($searching): array {
            $bids = $searching ? $this->bestBids($line) : collect();

            return [
                'id' => $line->id,
                'trade' => $line->trade->only(['id', 'code', 'label']),
                'status' => ['value' => $line->status->value, 'label' => $line->status->label()],
                'offers' => $bids->values()->map(fn (QuoteBid $bid, int $rank) => [...$this->bid($bid, $line), 'is_best' => $rank === 0])->all(),
                'selected' => $line->selectedBid ? $this->bid($line->selectedBid, $line) : null,
                'visit' => $line->selectedBid ? $this->visit($line->selectedBid) : null,
                'versions' => $line->versions->where('status', '!=', QuoteVersionStatus::Brouillon)->sortBy('version')->map(fn (QuoteVersion $version) => $this->version($version))->values()->all(),
                'quote' => $line->status === QuoteRequestTradeStatus::Realise ? $this->sheet($line) : null,
            ];
        });

        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'status' => ['value' => $request->status->value, 'label' => $request->status->label()],
            'notes' => $request->notes,
            'trades' => $trades->all(),
            'offers_count' => $trades->sum(fn (array $line) => count($line['offers'])),
            'ready_to_order' => $searching && $trades->every(fn (array $line) => $line['offers'] !== []),
            'total_amount' => $request->total_amount,
            'quotes_total_amount' => $request->status === QuoteRequestStatus::Recu ? (int) $lines->sum('quote_total_amount') : null,
            'can_cancel' => $searching,
            'response_deadline' => $request->response_deadline?->toIso8601String(),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'ordered_at' => $request->ordered_at?->toIso8601String(),
            'received_at' => $request->received_at?->toIso8601String(),
        ];
    }

    /**
     * Volet « Devis » : dernière version du devis réalisé par l’entrepreneur, présentée comme une page PDF.
     *
     * @return array<string, mixed>
     */
    public function sheet(QuoteRequestTrade $line): array
    {
        $site = $line->quoteRequest->site;
        $version = $line->versions->where('status', '!=', QuoteVersionStatus::Brouillon)->sortByDesc('version')->first();

        return [
            'number' => $line->quote_number,
            'version' => $version?->version,
            'issued_on' => $line->issued_on?->toDateString(),
            'valid_until' => $line->valid_until?->toDateString(),
            'technician' => [...$this->technician($line->selectedBid, $line), 'visited_on' => $line->visited_on?->toDateString(), 'distance_km' => $line->selectedBid->distance_km],
            'site' => ['label' => $site->label, 'location' => $site->address, 'trade' => $line->trade->label],
            'items' => $line->quote_items,
            'total_ht' => $version?->total_ht,
            'vat_amount' => $version?->vat_amount,
            'total_amount' => $line->quote_total_amount,
            'works_duration' => $version?->works_duration,
            'conditions' => $version?->conditions,
            'status' => ['value' => 'effectue', 'label' => 'Effectué'],
            'document' => $line->quoteDocument ? DocumentResource::make($line->quoteDocument->loadMissing(['documentType', 'files']))->resolve() : null,
        ];
    }

    /**
     * Version de devis, telle que la voient l’entrepreneur et le promoteur.
     *
     * @return array<string, mixed>
     */
    public function version(QuoteVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'version' => $version->version,
            'status' => ['value' => $version->status->value, 'label' => $version->status->label()],
            'items' => $version->items,
            'vat_applied' => $version->vat_applied,
            'vat_rate' => $version->vat_rate,
            'total_ht' => $version->total_ht,
            'vat_amount' => $version->vat_amount,
            'total_ttc' => $version->total_ttc,
            'works_duration' => $version->works_duration,
            'conditions' => $version->conditions,
            'validity_days' => $version->validity_days,
            'observations' => $version->observations,
            'signed_at' => $version->signed_at?->toIso8601String(),
            'sent_at' => $version->sent_at?->toIso8601String(),
            'received_at' => $version->received_at?->toIso8601String(),
            'document' => $version->document ? DocumentResource::make($version->document->loadMissing(['documentType', 'files']))->resolve() : null,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * Mission de visite d’une proposition commandée, et report en attente.
     *
     * @return array<string, mixed>|null
     */
    public function visit(QuoteBid $bid): ?array
    {
        if (! $bid->visit_status) {
            return null;
        }

        return [
            'status' => ['value' => $bid->visit_status->value, 'label' => $bid->visit_status->label()],
            'date' => $bid->visit_date->toDateString(),
            'slot' => $bid->visit_slot,
            'duration' => $bid->visit_duration,
            'visited_at' => $bid->visited_at?->toIso8601String(),
            'reschedule' => $bid->visit_status === QuoteVisitStatus::ReportPropose ? [
                'date' => $bid->reschedule_date?->toDateString(),
                'slot' => $bid->reschedule_slot,
                'reason' => $bid->reschedule_reason,
                'requested_at' => $bid->reschedule_requested_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bid(QuoteBid $bid, QuoteRequestTrade $line): array
    {
        return [
            'id' => $bid->id,
            'technician' => $this->technician($bid, $line),
            'trade' => $line->trade->label,
            'visit_date' => $bid->visit_date->toDateString(),
            'visit_slot' => $bid->visit_slot,
            'visit_duration' => $bid->visit_duration,
            'comment' => $bid->comment,
            'distance_km' => $bid->distance_km,
            'amount' => $bid->amount,
        ];
    }

    /**
     * Auteur de l’offre : entrepreneur (ou ancien ouvrier simulé).
     *
     * @return array<string, mixed>
     */
    private function technician(QuoteBid $bid, QuoteRequestTrade $line): array
    {
        if ($contractor = $bid->contractor) {
            return [
                'contractor_id' => $contractor->id,
                'kind' => ['value' => $contractor->kind->value, 'label' => $contractor->kind->label()],
                'name' => $contractor->name,
                'photo_url' => $contractor->logoMedia ? $this->media->temporaryUrl($contractor->logoMedia) : null,
                'rating' => ['average' => $contractor->rating_avg !== null ? (float) $contractor->rating_avg : null, 'count' => (int) $contractor->rating_count],
                'experience_years' => $contractor->experience_years,
            ];
        }

        $worker = $bid->worker;

        return [
            'contractor_id' => null,
            'kind' => null,
            'name' => trim($worker->first_name.' '.$worker->last_name),
            'photo_url' => $worker->photoMedia ? $this->media->temporaryUrl($worker->photoMedia) : null,
            'rating' => ['average' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null, 'count' => (int) $worker->rating_count],
            'experience_years' => $worker->workerTrades->firstWhere('trade_id', $line->trade_id)?->years_experience,
        ];
    }

    private function ensureMission(QuoteBid $bid): void
    {
        if ($bid->status !== QuoteBidStatus::Choisie || $bid->quoteRequestTrade->selected_bid_id !== $bid->getKey()) {
            throw ValidationException::withMessages(['proposal' => 'Cette proposition n’est pas commandée.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{total_ht: int, vat_amount: int, total_ttc: int}
     */
    private function totals(array $items, bool $vat, float $rate): array
    {
        $ht = (int) array_sum(array_column($items, 'total'));
        $tax = $vat ? (int) round($ht * $rate / 100) : 0;

        return ['total_ht' => $ht, 'vat_amount' => $tax, 'total_ttc' => $ht + $tax];
    }

    /**
     * PDF de la version du devis, enregistré comme « Devis estimatif » du chantier.
     */
    private function attachPdf(Site $site, QuoteBid $bid, QuoteRequestTrade $line, QuoteVersion $version, Model $author): Model
    {
        $contractor = $bid->contractor;
        $name = $contractor?->name ?? trim($bid->worker->first_name.' '.$bid->worker->last_name);
        $issued = $version->sent_at ?? now();
        $pdf = (new SimplePdf)
            ->title('Devis n° '.$version->number.' · version '.$version->version)
            ->text('O’CHANTIER · établi le '.$issued->format('d/m/Y').' · valable '.$version->validity_days.' jours', 10)
            ->heading('Professionnel')
            ->row('Nom', $name);

        if ($contractor) {
            $pdf->row('Type', $contractor->kind->label().($contractor->rccm ? ' · RCCM '.$contractor->rccm : ''));

            if ($phone = PhoneNumber::of($contractor)) {
                $pdf->row('Téléphone', $phone->formatted());
            }
        }

        $pdf->row('Métier', $line->trade->label)
            ->row('Date de visite', ($bid->visited_at ?? $bid->visit_date)->format('d/m/Y'))
            ->heading('Chantier')
            ->row('Nom', $site->label.' ('.$site->reference.')')
            ->row('Localisation', (string) $site->address)
            ->heading('Détail du devis');

        foreach ($version->items as $item) {
            $pdf->row($item['designation'].' · '.$item['quantity'].' '.($item['unit'] ?? 'u').' × '.$this->money($item['unit_price']), $this->money($item['total']));
        }

        $pdf->space(4)->row('Total HT', $this->money($version->total_ht));

        if ($version->vat_applied) {
            $pdf->row('TVA '.rtrim(rtrim(number_format($version->vat_rate, 2, ',', ''), '0'), ',').' %', $this->money($version->vat_amount));
        }

        $pdf->row($version->vat_applied ? 'Total TTC' : 'Montant total', $this->money($version->total_ttc), true)
            ->space()
            ->text('Durée des travaux : '.$version->works_duration)
            ->text((string) $version->conditions)
            ->text($version->observations ? 'Observations : '.$version->observations : '')
            ->space()
            ->text('Signé électroniquement par '.$name.' le '.$issued->format('d/m/Y à H:i').'.');

        $media = $this->media->storeContent($pdf->render(), 'Devis '.$line->trade->label.' '.$version->number.' v'.$version->version.'.pdf', 'application/pdf', 'document', $author);

        return $this->documents->attachGenerated($site, $author, DocumentType::query()->where('code', OfferDraft::QUOTE_DOCUMENT)->firstOrFail(), $media,
            'Devis '.mb_strtolower($line->trade->label).($version->version > 1 ? ' (v'.$version->version.')' : ''));
    }

    private function moveSite(Site $site, SiteStatus $to, Model $actor): void
    {
        $from = $site->status;
        $site->forceFill(['status' => $to, 'lock_version' => $site->lock_version + 1])->save();
        $site->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->getKey()]);
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}FCFA";
    }
}
