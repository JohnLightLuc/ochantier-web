<?php

namespace App\Support\Contractor;

use App\Enums\ContractorBidStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\QuoteRequestStatus;
use App\Enums\QuoteRequestTradeStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Document;
use App\Models\Offer;
use App\Models\OfferMilestone;
use App\Models\QuoteRequestTrade;
use App\Models\Site;
use App\Models\Trade;
use App\Support\Documents\DocumentStore;
use App\Support\Media\MediaStore;
use App\Support\Promoter\QuoteRequests;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Opportunités d’un entrepreneur (« Opportunités » : « Demandes de devis », « Offres contractuelles »).
 *
 * Une demande de devis est un corps de métier d’une demande en recherche ; elle est proposée aux entrepreneurs qui
 * exercent ce métier dans la ville du chantier (ville principale ou zone complémentaire). Une offre contractuelle
 * publiée, sans entrepreneur retenu, est proposée selon la ville du chantier et, quand le chantier a fait l’objet
 * d’une demande de devis, selon ses corps de métier ; sinon elle est ouverte à tous les métiers.
 *
 * La liste ne montre que les opportunités éligibles et encore ouvertes. Le détail reste consultable après la date
 * limite ou hors du profil, avec le motif. L’adresse exacte et les coordonnées du promoteur ne sont communiquées
 * qu’après l’acceptation et le paiement ; seuls le quartier, la commune et la ville sont affichés.
 */
class ContractorOpportunities
{
    /**
     * En dessous de ce délai (heures), la date limite est signalée comme proche.
     */
    public const SOON_HOURS = 36;

    public const QUOTES = 'quotes';

    public const OFFERS = 'offers';

    /**
     * Pièces du chantier montrées dans une opportunité (les autres restent internes).
     */
    private const PHOTO_TYPE = 'site_photo';

    private const SITE_WITH = ['site.commune.city', 'site.neighborhood', 'site.mainImageMedia'];

    public function __construct(private DocumentStore $documents, private MediaStore $media, private QuoteRequests $quotes) {}

    /**
     * Opportunités éligibles et ouvertes, triées par date limite ; meta : compteurs par onglet, opportunités hors
     * profil, métiers et villes du profil.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(Contractor $contractor, string $kind, ?string $search = null): array
    {
        $quotes = $this->searched($this->eligibleQuotes($contractor), $search, 'quoteRequest.site');
        $offers = $this->searched($this->eligibleOffers($contractor), $search, 'site');

        $items = $kind === self::OFFERS
            ? $offers->with([...self::SITE_WITH, 'site.quoteRequests.trades.trade'])->orderBy('application_deadline')->get()->map(fn (Offer $offer) => $this->offerCard($contractor, $offer))
            : $quotes->with(['trade', ...array_map(fn (string $relation) => 'quoteRequest.'.$relation, self::SITE_WITH), 'quoteRequest.site.documents.documentType'])->get()
                ->sortBy(fn (QuoteRequestTrade $line) => $line->quoteRequest->response_deadline)->values()->map(fn (QuoteRequestTrade $line) => $this->quoteCard($contractor, $line));

        return [
            'data' => $items->values()->all(),
            'meta' => [
                'kind' => $kind,
                'counts' => [self::QUOTES => $quotes->count(), self::OFFERS => $offers->count()],
                'excluded' => [
                    self::QUOTES => $this->openQuotes()->count() - $this->eligibleQuotes($contractor)->count(),
                    self::OFFERS => $this->openOffers()->count() - $this->eligibleOffers($contractor)->count(),
                ],
                'profile' => [
                    'trades' => $contractor->trades->sortBy('sort_order')->map->only(['id', 'code', 'label'])->values(),
                    'cities' => $this->cities($contractor)->values(),
                ],
                'can_respond' => $contractor->isVerified(),
                'notice' => $contractor->isVerified() ? null : 'Votre profil n’est pas encore vérifié : vous pouvez consulter les opportunités, mais pas y répondre.',
            ],
        ];
    }

    /**
     * « Demande de devis » : un corps de métier d’une demande (y compris clôturée, avec son état).
     *
     * @return array<string, mixed>|null
     */
    public function quote(Contractor $contractor, string $id): ?array
    {
        $line = QuoteRequestTrade::query()
            ->whereKey($id)
            ->whereHas('quoteRequest', fn (Builder $query) => $query->where('status', '!=', QuoteRequestStatus::Annule->value))
            ->with(['trade', 'quoteRequest.trades.trade', ...array_map(fn (string $relation) => 'quoteRequest.'.$relation, self::SITE_WITH)])
            ->first();

        if (! $line) {
            return null;
        }

        $request = $line->quoteRequest;
        $site = $request->site;
        $open = $this->isOpenQuote($line);
        $exclusion = $this->exclusion($contractor, $site, collect([$line->trade]));

        return [
            ...$this->quoteCard($contractor, $line),
            'need' => $request->notes,
            'site' => $this->siteDetail($site),
            'other_trades' => $request->trades->reject(fn (QuoteRequestTrade $other) => $other->is($line))
                ->map(fn (QuoteRequestTrade $other) => $other->trade->only(['id', 'code', 'label']))->values()->all(),
            'response' => $this->response($contractor, $open, $request->response_deadline, $exclusion, 'Proposer ma prestation de devis', $line->bids()->where('contractor_id', $contractor->getKey())->exists()),
            'proposal_options' => ['service_fee_percent' => $this->quotes->serviceFeePercent(), 'slots' => QuoteRequests::SLOTS, 'durations' => QuoteRequests::DURATIONS],
        ];
    }

    /**
     * « Offre contractuelle » : forfait proposé par le promoteur (y compris attribuée ou clôturée, avec son état).
     *
     * @return array<string, mixed>|null
     */
    public function offer(Contractor $contractor, string $id): ?array
    {
        $offer = Offer::query()
            ->whereKey($id)
            ->where('offer_type', OfferType::Contractuelle->value)
            ->whereNotIn('status', [OfferStatus::Brouillon->value, OfferStatus::Soumise->value, OfferStatus::EnControle->value, OfferStatus::ACorriger->value])
            ->with([...self::SITE_WITH, 'site.quoteRequests.trades.trade', 'milestones', 'quoteDocument.documentType', 'quoteDocument.files'])
            ->first();

        if (! $offer) {
            return null;
        }

        $open = $this->isOpenOffer($offer);
        $exclusion = $this->exclusion($contractor, $offer->site, $this->offerTrades($offer));
        $quote = $offer->quoteDocument && ! $offer->quoteDocument->documentType->is_sensitive ? DocumentResource::make($offer->quoteDocument)->resolve() : null;

        return [
            ...$this->offerCard($contractor, $offer),
            'description' => $offer->description,
            'deliverables' => $offer->deliverables,
            'payment_mode' => $offer->payment_mode ? ['value' => $offer->payment_mode->value, 'label' => $offer->payment_mode->label()] : null,
            'milestones' => $offer->milestones->sortBy('sequence')->map(fn (OfferMilestone $milestone) => [
                'label' => $milestone->label,
                'amount' => $milestone->amount,
                'due_date' => $milestone->due_date?->format('Y-m-d'),
            ])->values()->all(),
            'quote_document' => $quote,
            'site' => $this->siteDetail($offer->site),
            'terms' => app(ContractorOfferResponses::class)->terms($offer),
            'response' => $this->response($contractor, $open, $offer->application_deadline, $exclusion,
                ($draft = ContractorBid::query()->where('offer_id', $offer->getKey())->where('contractor_id', $contractor->getKey())->first())?->status === ContractorBidStatus::Brouillon ? 'Reprendre mon brouillon' : 'Répondre à l’offre',
                $draft !== null && $draft->status !== ContractorBidStatus::Brouillon),
            'draft_id' => $draft?->status === ContractorBidStatus::Brouillon ? $draft->id : null,
        ];
    }

    /**
     * Corps de métier en recherche, dont la demande attend encore des offres.
     *
     * @return Builder<QuoteRequestTrade>
     */
    public function openQuotes(): Builder
    {
        return QuoteRequestTrade::query()
            ->where('status', QuoteRequestTradeStatus::Recherche->value)
            ->whereHas('quoteRequest', fn (Builder $query) => $query->where('status', QuoteRequestStatus::Recherche->value)->where('response_deadline', '>', now()));
    }

    /**
     * Offres contractuelles publiées, sans entrepreneur retenu, avant leur date limite.
     *
     * @return Builder<Offer>
     */
    public function openOffers(): Builder
    {
        return Offer::query()
            ->where('offer_type', OfferType::Contractuelle->value)
            ->where('status', OfferStatus::Publiee->value)
            ->where('applications_open', true)
            ->whereNull('contractor_bid_id')
            ->where(fn (Builder $query) => $query->whereNull('application_deadline')->orWhere('application_deadline', '>', now()));
    }

    /**
     * @return Builder<QuoteRequestTrade>
     */
    private function eligibleQuotes(Contractor $contractor): Builder
    {
        return $this->openQuotes()
            ->whereIn('trade_id', $contractor->trades->modelKeys())
            ->whereHas('quoteRequest.site.commune', fn (Builder $query) => $query->whereIn('city_id', $this->cities($contractor)->pluck('id')));
    }

    /**
     * @return Builder<Offer>
     */
    private function eligibleOffers(Contractor $contractor): Builder
    {
        $trades = $contractor->trades->modelKeys();

        return $this->openOffers()
            ->whereHas('site.commune', fn (Builder $query) => $query->whereIn('city_id', $this->cities($contractor)->pluck('id')))
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('site.quoteRequests', fn (Builder $query) => $query->where('status', '!=', QuoteRequestStatus::Annule->value))
                ->orWhereHas('site.quoteRequests', fn (Builder $query) => $query->where('status', '!=', QuoteRequestStatus::Annule->value)->whereHas('trades', fn (Builder $query) => $query->whereIn('trade_id', $trades))));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function searched(Builder $query, ?string $search, string $site): Builder
    {
        $search = trim((string) $search);

        return $query->when($search !== '', fn (Builder $query) => $query->whereHas($site, fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->where('label', 'ilike', '%'.$search.'%')
            ->orWhere('reference', 'ilike', '%'.$search.'%')
            ->orWhereHas('commune', fn (Builder $query) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->orWhereHas('neighborhood', fn (Builder $query) => $query->where('name', 'ilike', '%'.$search.'%')))));
    }

    /**
     * Ville principale puis villes complémentaires.
     *
     * @return Collection<int, array{id: string, name: string}>
     */
    private function cities(Contractor $contractor): Collection
    {
        return collect([$contractor->city, ...$contractor->zones->sortBy('name')])->filter()->map->only(['id', 'name'])->unique('id')->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteCard(Contractor $contractor, QuoteRequestTrade $line): array
    {
        $request = $line->quoteRequest;
        $site = $request->site;
        $documents = $this->siteDocuments($site);

        return [
            'id' => $line->id,
            'kind' => ['value' => 'quote_request', 'label' => 'Demande de devis'],
            'reference' => $request->reference,
            'trade' => $line->trade->only(['id', 'code', 'label']),
            'site' => $this->siteCard($site),
            'published_at' => $request->submitted_at?->toIso8601String(),
            'deadline' => $this->deadline($request->response_deadline),
            'documents_count' => $documents->reject(fn (Document $document) => $document->documentType->code === self::PHOTO_TYPE)->count(),
            'photos_count' => $documents->filter(fn (Document $document) => $document->documentType->code === self::PHOTO_TYPE)->count() + ($site->mainImageMedia ? 1 : 0),
            'status' => $this->status($this->isOpenQuote($line)),
            'my_response' => ($bid = $line->bids()->where('contractor_id', $contractor->getKey())->first()) ? [
                'id' => $bid->id,
                'amount' => $bid->amount,
                'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offerCard(Contractor $contractor, Offer $offer): array
    {
        $bid = ContractorBid::query()->where('offer_id', $offer->getKey())->where('contractor_id', $contractor->getKey())->first();
        $days = $offer->end_date ? (int) $offer->start_date->diffInDays($offer->end_date) + 1 : null;

        return [
            'id' => $offer->id,
            'kind' => ['value' => 'contractor_offer', 'label' => 'Offre contractuelle'],
            'reference' => $offer->reference,
            'title' => $offer->title,
            'trades' => $this->offerTrades($offer)->map->only(['id', 'code', 'label'])->values()->all(),
            'site' => $this->siteCard($offer->site),
            'amount' => $offer->contract_total_amount,
            'period' => ['start_date' => $offer->start_date?->format('Y-m-d'), 'end_date' => $offer->end_date?->format('Y-m-d'), 'days' => $days],
            'published_at' => $offer->published_at?->toIso8601String(),
            'deadline' => $this->deadline($offer->application_deadline),
            'status' => $this->status($this->isOpenOffer($offer)),
            'my_response' => $bid ? [
                'id' => $bid->id,
                'amount' => $bid->amount,
                'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
                'attributed' => $bid->status === ContractorBidStatus::Retenue,
            ] : null,
        ];
    }

    /**
     * Corps de métier de l’offre : ceux de la demande de devis du chantier, s’il y en a eu une.
     *
     * @return Collection<int, Trade>
     */
    private function offerTrades(Offer $offer): Collection
    {
        return $offer->site->quoteRequests
            ->reject(fn ($request) => $request->status === QuoteRequestStatus::Annule)
            ->sortByDesc('submitted_at')
            ->first()?->trades->map->trade->values() ?? collect();
    }

    private function isOpenQuote(QuoteRequestTrade $line): bool
    {
        return $line->status === QuoteRequestTradeStatus::Recherche && $line->quoteRequest->status === QuoteRequestStatus::Recherche
            && ! $this->deadline($line->quoteRequest->response_deadline)['expired'];
    }

    private function isOpenOffer(Offer $offer): bool
    {
        return $offer->status === OfferStatus::Publiee && $offer->applications_open && ! $offer->contractor_bid_id
            && ! $this->deadline($offer->application_deadline)['expired'];
    }

    /**
     * Chantier tel que montré avant la commande : référence, titre, quartier, commune et ville.
     *
     * @return array<string, mixed>
     */
    private function siteCard(Site $site): array
    {
        return [
            'id' => $site->id,
            'reference' => $site->reference,
            'title' => $site->label,
            'neighborhood' => $site->neighborhood?->name,
            'commune' => $site->commune?->name,
            'city' => $site->commune?->city?->only(['id', 'name']),
            'place' => collect([$site->neighborhood?->name ?? $site->commune?->name, $site->commune?->city?->name])->filter()->unique()->implode(', '),
            'image_url' => $site->mainImageMedia ? $this->media->temporaryUrl($site->mainImageMedia) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteDetail(Site $site): array
    {
        $documents = $this->siteDocuments($site);

        return [
            ...$this->siteCard($site),
            'description' => $site->description,
            'constraints' => $site->access_instructions,
            'planned_start_date' => $site->planned_start_date?->format('Y-m-d'),
            'planned_end_date' => $site->planned_end_date?->format('Y-m-d'),
            'planned_duration_days' => $site->planned_duration_days,
            'area' => $site->commune?->location ? ['latitude' => $site->commune->location['latitude'] ?? null, 'longitude' => $site->commune->location['longitude'] ?? null] : null,
            'masked_notice' => 'Adresse exacte et coordonnées du promoteur communiquées après acceptation et paiement de la commande.',
            'promoter' => 'Coordonnées masquées jusqu’à la commande',
            'photos' => [
                ...($site->mainImageMedia ? [['id' => $site->mainImageMedia->id, 'url' => $this->media->temporaryUrl($site->mainImageMedia)]] : []),
                ...$documents->filter(fn (Document $document) => $document->documentType->code === self::PHOTO_TYPE)
                    ->flatMap(fn (Document $document) => $document->files->map(fn ($file) => ['id' => $file->id, 'url' => $this->media->temporaryUrl($file)]))->values()->all(),
            ],
            'documents' => $documents->reject(fn (Document $document) => $document->documentType->code === self::PHOTO_TYPE)
                ->map(fn (Document $document) => DocumentResource::make($document)->resolve())->values()->all(),
        ];
    }

    /**
     * Pièces du chantier non sensibles (plans, cahier des charges, photos…).
     *
     * @return Collection<int, Document>
     */
    private function siteDocuments(Site $site): Collection
    {
        return $this->documents->active($site)->reject(fn (Document $document) => $document->documentType->is_sensitive)->values();
    }

    /**
     * Motif pour lequel l’opportunité ne correspond pas au profil, ou null.
     *
     * @param  Collection<int, Trade>  $trades  Corps de métier demandés (aucun : tous).
     */
    private function exclusion(Contractor $contractor, Site $site, Collection $trades): ?string
    {
        $reasons = [];

        if (! $this->cities($contractor)->contains('id', $site->commune?->city_id)) {
            $reasons[] = 'chantier hors de votre zone d’intervention';
        }

        if ($trades->isNotEmpty() && $trades->pluck('id')->intersect($contractor->trades->modelKeys())->isEmpty()) {
            $reasons[] = 'métier non exercé';
        }

        return $reasons ? implode(', ', $reasons) : null;
    }

    /**
     * Possibilité de répondre, et motif affiché sous le bouton sinon.
     *
     * @return array{can_respond: bool, action: string, reason: string|null, eligible: bool, answered: bool}
     */
    private function response(Contractor $contractor, bool $open, ?CarbonInterface $deadline, ?string $exclusion, string $action, bool $answered = false): array
    {
        $reason = match (true) {
            $answered => 'Vous avez déjà répondu : consultez votre proposition.',
            $exclusion !== null => 'Cette opportunité ne correspond pas à votre profil : '.$exclusion.'.',
            ! $open && $this->deadline($deadline)['expired'] => 'Le délai de réponse est expiré.',
            ! $open => 'Cette opportunité n’attend plus de réponse.',
            ! $contractor->isVerified() => 'Vérification du profil requise pour répondre.',
            default => null,
        };

        return [
            'can_respond' => $reason === null,
            'action' => match (true) {
                $answered => 'Voir ma proposition',
                $exclusion !== null => 'Non éligible',
                default => $action,
            },
            'reason' => $reason,
            'eligible' => $exclusion === null,
            'answered' => $answered,
        ];
    }

    /**
     * @return array{at: string|null, expired: bool, soon: bool, remaining_seconds: int|null}
     */
    private function deadline(?CarbonInterface $deadline): array
    {
        $remaining = $deadline ? (int) now()->diffInSeconds($deadline, false) : null;

        return [
            'at' => $deadline?->toIso8601String(),
            'expired' => $remaining !== null && $remaining <= 0,
            'soon' => $remaining !== null && $remaining > 0 && $remaining < self::SOON_HOURS * 3600,
            'remaining_seconds' => $remaining !== null ? max(0, $remaining) : null,
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function status(bool $open): array
    {
        return $open ? ['value' => 'ouverte', 'label' => 'Ouverte'] : ['value' => 'fermee', 'label' => 'Fermée'];
    }
}
