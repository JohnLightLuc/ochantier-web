<?php

namespace App\Support\Promoter;

use App\Enums\QuoteBidStatus;
use App\Enums\QuoteRequestStatus;
use App\Enums\QuoteRequestTradeStatus;
use App\Enums\SiteStatus;
use App\Http\Resources\V1\DocumentResource;
use App\Models\DocumentType;
use App\Models\PaymentTransaction;
use App\Models\QuoteBid;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestTrade;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Documents\DocumentStore;
use App\Support\Documents\SimplePdf;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demande de devis d’une offre contractuelle (« Avez-vous un devis ? » → Non).
 *
 * « Rechercher un technicien » crée la demande (un corps de métier par ligne) et passe le chantier au statut
 * « devis ». Les techniciens, ouvriers validés du métier, répondent par une offre (prix, date d’exécution) : le
 * promoteur voit les BEST_BIDS meilleures par métier (note décroissante, puis prix croissant), en choisit une par
 * métier et paie la « Commande des devis » en une fois. Chaque devis réalisé est ajouté aux documents du chantier ;
 * quand tous le sont, le chantier revient au brouillon avec « Oui » répondu à « Avez-vous un devis ? » : il reste à
 * définir l’offre financière et à publier.
 */
class QuoteRequests
{
    /**
     * Offres affichées par corps de métier.
     */
    public const BEST_BIDS = 3;

    /**
     * Durée de validité d’un devis réalisé (jours).
     */
    public const VALIDITY_DAYS = 30;

    public function __construct(private OfferDraft $drafts, private DocumentStore $documents, private MediaStore $media) {}

    /**
     * « Rechercher un technicien » : crée la demande à partir du brouillon et lance la recherche.
     */
    public function submit(Site $site, User $author): QuoteRequest
    {
        if ($site->status !== SiteStatus::Brouillon) {
            throw ValidationException::withMessages(['site' => 'Ce chantier n’est plus un brouillon.']);
        }

        if ($errors = $this->drafts->publicationErrors($site)) {
            throw ValidationException::withMessages(['publication' => array_column($errors, 'label')]);
        }

        return DB::transaction(function () use ($site, $author): QuoteRequest {
            $request = $site->quoteRequests()->create([
                'reference' => sprintf('DV-%s-%04d', now()->format('Y'), DB::scalar("SELECT nextval('quote_request_reference_seq')")),
                'status' => QuoteRequestStatus::Recherche,
                'notes' => $site->offer_draft['contract']['quote_request']['notes'] ?? null,
                'requested_by_user_id' => $author->getKey(),
                'submitted_at' => now(),
            ]);

            foreach ($this->drafts->quoteTrades($site) as $trade) {
                $request->trades()->create(['trade_id' => $trade->getKey(), 'status' => QuoteRequestTradeStatus::Recherche]);
            }

            $this->moveSite($site, SiteStatus::Devis, $author);

            return $request;
        });
    }

    /**
     * Annule une demande encore en recherche (aucun paiement) : le chantier revient au brouillon.
     */
    public function cancel(Site $site, User $author): void
    {
        $request = $this->current($site);

        if ($request?->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_request' => 'Seule une demande de devis en recherche peut être annulée.']);
        }

        DB::transaction(function () use ($site, $request, $author): void {
            $request->update(['status' => QuoteRequestStatus::Annule, 'cancelled_at' => now()]);
            $this->moveSite($site, SiteStatus::Brouillon, $author);
        });
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
     * Offre d’un technicien pour un corps de métier encore en recherche (une par technicien). La distance est
     * calculée entre son domicile et le chantier.
     */
    public function addBid(QuoteRequestTrade $line, Worker $worker, int $amount, CarbonImmutable $visitDate): QuoteBid
    {
        if ($line->status !== QuoteRequestTradeStatus::Recherche) {
            throw ValidationException::withMessages(['quote_request' => 'Ce corps de métier n’attend plus d’offre.']);
        }

        $distance = DB::scalar('SELECT ST_Distance(w.residence_location, s.location) / 1000 FROM workers w, sites s WHERE w.id = ? AND s.id = ?', [$worker->getKey(), $line->quoteRequest->site_id]);

        return $line->bids()->updateOrCreate(['worker_id' => $worker->getKey()], [
            'amount' => $amount,
            'visit_date' => $visitDate->toDateString(),
            'distance_km' => $distance !== null ? round((float) $distance, 1) : null,
            'status' => QuoteBidStatus::Proposee,
        ]);
    }

    /**
     * Meilleures offres d’un corps de métier : note du technicien décroissante, puis prix croissant.
     *
     * @return Collection<int, QuoteBid>
     */
    public function bestBids(QuoteRequestTrade $line): Collection
    {
        return $line->bids()
            ->with(['worker.photoMedia', 'worker.workerTrades'])
            ->join('workers', 'workers.id', '=', 'quote_bids.worker_id')
            ->orderByRaw('workers.rating_avg DESC NULLS LAST')
            ->orderBy('quote_bids.amount')
            ->orderBy('quote_bids.created_at')
            ->limit(self::BEST_BIDS)
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
     * Paiement réussi : les offres choisies sont commandées, les autres ne sont pas retenues.
     *
     * @param  Collection<int, QuoteBid>  $bids
     */
    public function order(QuoteRequest $request, Collection $bids, PaymentTransaction $transaction, User $author): void
    {
        $request = QuoteRequest::query()->lockForUpdate()->findOrFail($request->getKey());

        if ($request->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_bid_ids' => 'Ces devis sont déjà commandés.']);
        }

        foreach ($request->trades as $line) {
            $chosen = $bids->firstWhere('quote_request_trade_id', $line->getKey());
            $line->bids()->whereKeyNot($chosen->getKey())->update(['status' => QuoteBidStatus::NonRetenue->value]);
            $chosen->update(['status' => QuoteBidStatus::Choisie]);
            $line->update(['status' => QuoteRequestTradeStatus::Commande, 'selected_bid_id' => $chosen->getKey()]);
        }

        $request->update([
            'status' => QuoteRequestStatus::Commande,
            'total_amount' => (int) $bids->sum('amount'),
            'payment_transaction_id' => $transaction->getKey(),
            'ordered_at' => now(),
        ]);
    }

    /**
     * Devis réalisé par le technicien commandé : il est ajouté aux documents du chantier (PDF). Le dernier devis
     * reçu ramène le chantier au brouillon, « Avez-vous un devis ? » répondu par Oui.
     *
     * @param  list<array{designation: string, unit_price: int, quantity: float|int}>  $items
     */
    public function complete(QuoteRequestTrade $line, array $items, CarbonImmutable $visitedOn): QuoteRequestTrade
    {
        if ($line->status !== QuoteRequestTradeStatus::Commande) {
            throw ValidationException::withMessages(['quote_request' => 'Ce devis n’est pas commandé ou est déjà réalisé.']);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Le devis doit comporter au moins une ligne.']);
        }

        return DB::transaction(function () use ($line, $items, $visitedOn): QuoteRequestTrade {
            $items = array_map(fn (array $item) => [
                'designation' => $item['designation'],
                'unit_price' => (int) $item['unit_price'],
                'quantity' => $item['quantity'],
                'total' => (int) round($item['unit_price'] * $item['quantity']),
            ], $items);
            $line->forceFill([
                'quote_number' => sprintf('DE-%s-%05d', now()->format('Y'), DB::scalar("SELECT nextval('site_quote_number_seq')")),
                'quote_items' => $items,
                'quote_total_amount' => array_sum(array_column($items, 'total')),
                'visited_on' => $visitedOn->toDateString(),
                'issued_on' => today()->toDateString(),
                'valid_until' => today()->addDays(self::VALIDITY_DAYS)->toDateString(),
            ]);
            $request = $line->quoteRequest;
            $site = $request->site;
            $worker = $line->selectedBid->worker;
            $document = $this->attachPdf($site, $worker, $line);
            $line->forceFill(['quote_document_id' => $document->getKey(), 'status' => QuoteRequestTradeStatus::Realise, 'completed_at' => now()])->save();

            if (! $request->trades()->where('status', '!=', QuoteRequestTradeStatus::Realise->value)->exists()) {
                $request->update(['status' => QuoteRequestStatus::Recu, 'received_at' => now()]);
                $draft = $site->offer_draft ?? [];
                $draft['type'] = OfferDraft::CONTRACT;
                $draft['contract'] = [...($draft['contract'] ?? []), 'has_quote' => true];
                $site->forceFill(['offer_draft' => $draft]);
                $this->moveSite($site, SiteStatus::Brouillon, $worker);
            }

            return $line->refresh();
        });
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
            $request->status === QuoteRequestStatus::Recherche && $request->trades()->whereDoesntHave('bids')->doesntExist() => ['value' => 'choisir_devis', 'label' => 'Choisir les offres de devis'],
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
        $lines = $request->trades()->with(['trade', 'selectedBid.worker.photoMedia', 'selectedBid.worker.workerTrades', 'quoteDocument.documentType', 'quoteDocument.files'])->get()
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
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'ordered_at' => $request->ordered_at?->toIso8601String(),
            'received_at' => $request->received_at?->toIso8601String(),
        ];
    }

    /**
     * Volet « Devis » : devis réalisé par un technicien O’CHANTIER, présenté comme une page PDF.
     *
     * @return array<string, mixed>
     */
    public function sheet(QuoteRequestTrade $line): array
    {
        $site = $line->quoteRequest->site;

        return [
            'number' => $line->quote_number,
            'issued_on' => $line->issued_on?->toDateString(),
            'valid_until' => $line->valid_until?->toDateString(),
            'technician' => [...$this->technician($line->selectedBid->worker, $line), 'visited_on' => $line->visited_on?->toDateString(), 'distance_km' => $line->selectedBid->distance_km],
            'site' => ['label' => $site->label, 'location' => $site->address, 'trade' => $line->trade->label],
            'items' => $line->quote_items,
            'total_amount' => $line->quote_total_amount,
            'status' => ['value' => 'effectue', 'label' => 'Effectué'],
            'document' => $line->quoteDocument ? DocumentResource::make($line->quoteDocument->loadMissing(['documentType', 'files']))->resolve() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bid(QuoteBid $bid, QuoteRequestTrade $line): array
    {
        return [
            'id' => $bid->id,
            'technician' => $this->technician($bid->worker, $line),
            'trade' => $line->trade->label,
            'visit_date' => $bid->visit_date->toDateString(),
            'distance_km' => $bid->distance_km,
            'amount' => $bid->amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technician(Worker $worker, QuoteRequestTrade $line): array
    {
        /** @var WorkerTrade|null $trade */
        $trade = $worker->workerTrades->firstWhere('trade_id', $line->trade_id);

        return [
            'worker_id' => $worker->id,
            'name' => trim($worker->first_name.' '.$worker->last_name),
            'photo_url' => $worker->photoMedia ? $this->media->temporaryUrl($worker->photoMedia) : null,
            'rating' => ['average' => $worker->rating_avg !== null ? (float) $worker->rating_avg : null, 'count' => (int) $worker->rating_count],
            'experience_years' => $trade?->years_experience,
        ];
    }

    /**
     * PDF du devis réalisé, enregistré comme « Devis estimatif » du chantier.
     */
    private function attachPdf(Site $site, Worker $worker, QuoteRequestTrade $line): Model
    {
        $name = trim($worker->first_name.' '.$worker->last_name);
        $pdf = (new SimplePdf)
            ->title('Devis n° '.$line->quote_number)
            ->text('O’CHANTIER · établi le '.$line->issued_on->format('d/m/Y').' · valable jusqu’au '.$line->valid_until->format('d/m/Y'), 10)
            ->heading('Technicien')
            ->row('Nom', $name)
            ->row('Métier', $line->trade->label)
            ->row('Date de visite', $line->visited_on->format('d/m/Y'))
            ->heading('Chantier')
            ->row('Nom', $site->label.' ('.$site->reference.')')
            ->row('Localisation', (string) $site->address)
            ->heading('Détail du devis');

        foreach ($line->quote_items as $item) {
            $pdf->row($item['designation'].' · '.$item['quantity'].' × '.$this->money($item['unit_price']), $this->money($item['total']));
        }

        $pdf->space(4)->row('Montant total', $this->money($line->quote_total_amount), true)->space()->text('Signé par '.$name.', technicien O’CHANTIER.');

        $media = $this->media->storeContent($pdf->render(), 'Devis '.$line->trade->label.' '.$line->quote_number.'.pdf', 'application/pdf', 'document', $worker);

        return $this->documents->attachGenerated($site, $worker, DocumentType::query()->where('code', OfferDraft::QUOTE_DOCUMENT)->firstOrFail(), $media, 'Devis '.mb_strtolower($line->trade->label));
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
