<?php

namespace App\Support\Contractor;

use App\Enums\ContractorBidStatus;
use App\Enums\ContractorResponseMode;
use App\Enums\ContractPaymentMode;
use App\Enums\PricingVariable;
use App\Models\Contractor;
use App\Models\ContractorBid;
use App\Models\Offer;
use App\Models\Site;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use App\Support\Pricing\PricingRules;
use App\Support\Promoter\ContractorOffers;
use App\Support\Promoter\OfferDraft;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Réponses d’un entrepreneur aux offres contractuelles (« Accepter les conditions », « Contre-proposition »,
 * « Mon offre contractuelle ») et liste « Mes offres » (réponses et propositions de devis).
 *
 * Une offre conforme reprend le forfait du promoteur (montant net, hors commission O’CHANTIER) et ses dates ; une
 * contre-proposition change le prix, les dates et l’équipe. L’échéancier reste celui de la plateforme : acompte X
 * payé par le promoteur à l’attribution, puis le solde au rythme de son offre (hebdomadaire, par étape ou en fin de
 * chantier). Répondre ne vaut pas attribution : le chantier est attribué après le choix du promoteur et l’acompte.
 */
class ContractorOfferResponses
{
    /**
     * Onglets de « Mes offres » => statuts des réponses et des propositions de devis.
     *
     * @var array<string, array{label: string, responses: list<ContractorBidStatus>, quotes: list<string>}>
     */
    public const TABS = [
        'brouillons' => ['label' => 'Brouillons', 'responses' => [ContractorBidStatus::Brouillon], 'quotes' => []],
        'en_attente' => ['label' => 'En attente', 'responses' => [ContractorBidStatus::Proposee], 'quotes' => ['proposee']],
        'acceptees' => ['label' => 'Acceptées', 'responses' => [ContractorBidStatus::Choisie, ContractorBidStatus::Retenue], 'quotes' => ['choisie']],
        'refusees' => ['label' => 'Refusées', 'responses' => [ContractorBidStatus::NonRetenue, ContractorBidStatus::Annulee], 'quotes' => ['non_retenue']],
        'retirees' => ['label' => 'Retirées', 'responses' => [], 'quotes' => ['retiree']],
    ];

    private const WITH = ['offer.site.commune.city', 'offer.site.neighborhood', 'offer.site.mainImageMedia', 'offer.site.promoterProfile.user', 'offer.site.responsibleUser'];

    public function __construct(
        private ContractorOffers $offers,
        private ContractorOpportunities $opportunities,
        private ContractorQuoteProposals $quotes,
        private PricingRules $pricing,
        private OfferDraft $drafts,
        private MediaStore $media,
    ) {}

    /**
     * Enregistre la réponse en brouillon, ou l’envoie au promoteur.
     *
     * @param  array{mode: string, amount?: int|null, start_date?: string|null, end_date?: string|null, team_size: int, message?: string|null, draft?: bool}  $data
     */
    public function respond(Contractor $contractor, string $offerId, array $data): ContractorBid
    {
        $offer = Offer::query()->with('site')->find($offerId);
        $detail = $offer ? $this->opportunities->offer($contractor, $offerId) : null;

        abort_unless($offer && $detail, 404, 'Offre contractuelle introuvable.');

        if (! $detail['response']['can_respond']) {
            throw ValidationException::withMessages(['response' => $detail['response']['reason']]);
        }

        $mode = ContractorResponseMode::from($data['mode']);
        [$start, $end] = $mode === ContractorResponseMode::Conforme
            ? $this->offerPeriod($offer)
            : [CarbonImmutable::parse($data['start_date']), CarbonImmutable::parse($data['end_date'])];
        $amount = $mode === ContractorResponseMode::Conforme ? $this->offers->conformingAmount($offer) : (int) $data['amount'];

        $bid = $this->offers->addBid($offer, $contractor, $amount, (int) $start->diffInDays($end) + 1, (int) $data['team_size'], $data['message'] ?? null, $mode, $start, $end, (bool) ($data['draft'] ?? false));

        return $this->find($contractor, $bid->id);
    }

    /**
     * Supprime un brouillon.
     */
    public function deleteDraft(ContractorBid $bid): void
    {
        if ($bid->status !== ContractorBidStatus::Brouillon) {
            throw ValidationException::withMessages(['response' => 'Seul un brouillon peut être supprimé.']);
        }

        $bid->delete();
    }

    public function find(Contractor $contractor, string $id): ContractorBid
    {
        $bid = str($id)->isUuid() ? ContractorBid::query()->where('contractor_id', $contractor->getKey())->with(self::WITH)->find($id) : null;

        abort_unless($bid, 404, 'Réponse introuvable.');

        return $bid;
    }

    /**
     * « Mes offres » : réponses aux offres contractuelles et propositions de devis de l’onglet, les plus récentes
     * d’abord ; meta : compteurs par onglet.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(Contractor $contractor, string $tab, ?string $search = null): array
    {
        $search = mb_strtolower(trim((string) $search));
        $responses = ContractorBid::query()->where('contractor_id', $contractor->getKey())->with(self::WITH)->get()
            ->filter(fn (ContractorBid $bid) => $search === '' || str_contains(mb_strtolower($bid->offer->site->label), $search));
        $quotes = collect($this->allQuotes($contractor, $search));

        $items = fn (string $key) => collect()
            ->concat($responses->filter(fn (ContractorBid $bid) => in_array($bid->status, self::TABS[$key]['responses'], true))->map(fn (ContractorBid $bid) => $this->card($bid)))
            ->concat($quotes->filter(fn (array $card) => in_array($card['status']['value'], self::TABS[$key]['quotes'], true)))
            ->sortByDesc(fn (array $card) => $card['sent_at'] ?? $card['updated_at'] ?? '')
            ->values();

        return [
            'data' => $items($tab)->all(),
            'meta' => [
                'tab' => $tab,
                'tabs' => collect(self::TABS)->map(fn (array $definition, string $key) => ['key' => $key, 'label' => $definition['label'], 'count' => $items($key)->count()])->values()->all(),
            ],
        ];
    }

    /**
     * Carte de « Mes offres ».
     *
     * @return array<string, mixed>
     */
    public function card(ContractorBid $bid): array
    {
        $site = $bid->offer->site;
        $prices = $this->offers->prices($bid);

        return [
            'id' => $bid->id,
            'kind' => ['value' => 'offer_response', 'label' => 'Offre contractuelle'],
            'offer_id' => $bid->offer_id,
            'reference' => $bid->offer->reference,
            'site' => [
                'id' => $site->id,
                'reference' => $site->reference,
                'title' => $site->label,
                'place' => collect([$site->neighborhood?->name ?? $site->commune?->name, $site->commune?->city?->name])->filter()->unique()->implode(', '),
                'image_url' => $site->mainImageMedia ? $this->media->temporaryUrl($site->mainImageMedia) : null,
            ],
            'response_mode' => ['value' => $bid->response_mode->value, 'label' => $bid->response_mode->label()],
            'amount' => $bid->amount,
            'promoter_amount' => $prices['total'],
            'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
            'display_status' => $this->displayStatus($bid),
            'sent_at' => $bid->sent_at?->toIso8601String(),
            'updated_at' => $bid->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Écran « Mon offre contractuelle » : conditions envoyées (ou du brouillon), conditions initiales, comparaison,
     * échéancier, suivi et chantier attribué.
     *
     * @return array<string, mixed>
     */
    public function detail(ContractorBid $bid): array
    {
        $offer = $bid->offer;
        $site = $offer->site;
        $prices = $this->offers->prices($bid);
        $initialAmount = $this->offers->conformingAmount($offer);
        [$initialStart, $initialEnd] = $this->offerPeriod($offer);
        $attributed = $bid->status === ContractorBidStatus::Retenue;

        return [
            ...$this->card($bid),
            'conditions' => [
                'amount' => $bid->amount,
                'start_date' => $bid->start_date?->toDateString(),
                'end_date' => $bid->end_date?->toDateString(),
                'duration_days' => $bid->duration_days,
                'team_size' => $bid->team_size,
                'message' => $bid->message,
            ],
            'initial_conditions' => [
                'amount' => $initialAmount,
                'promoter_amount' => (int) $offer->contract_total_amount,
                'start_date' => $initialStart->toDateString(),
                'end_date' => $initialEnd->toDateString(),
                'duration_days' => (int) $initialStart->diffInDays($initialEnd) + 1,
            ],
            'comparison' => [
                'amount_difference' => $bid->amount - $initialAmount,
                'start_shift_days' => $bid->start_date ? (int) $initialStart->diffInDays($bid->start_date, false) : 0,
                'duration_difference_days' => $bid->duration_days - ((int) $initialStart->diffInDays($initialEnd) + 1),
            ],
            'money' => [
                'net_amount' => $bid->amount,
                'commission_percent' => $this->pricing->value(PricingVariable::Y),
                'commission_amount' => $prices['commission'],
                'promoter_amount' => $prices['total'],
                'deposit_percent' => $this->pricing->value(PricingVariable::X),
                'deposit_amount' => $prices['deposit'],
            ],
            'schedule' => $this->schedule($offer, $bid->amount, $bid->start_date ?? $initialStart, $bid->duration_days),
            'tracking' => $this->tracking($bid),
            'notice' => match ($bid->status) {
                ContractorBidStatus::Brouillon => 'Ce brouillon n’a pas été transmis au promoteur.',
                ContractorBidStatus::Proposee => 'Le promoteur compare au maximum '.ContractorOffers::BEST_BIDS.' offres et en retient une seule.',
                ContractorBidStatus::Choisie => 'Votre offre est retenue. L’attribution sera effective après la confirmation de l’acompte du promoteur. Aucune réservation ferme à ce stade.',
                ContractorBidStatus::NonRetenue => 'Offre non retenue. Elle reste consultable ; la gestion du chantier n’est pas disponible.',
                ContractorBidStatus::Annulee => 'Cette offre n’est plus active. Elle reste consultable.',
                default => null,
            },
            'attribution' => $attributed ? [
                'site_id' => $site->id,
                'start_date' => $offer->needs()->latest()->first()?->start_date?->toDateString(),
                'address' => $site->address,
                'location' => $site->location,
                'promoter' => $this->promoter($site),
            ] : null,
            'actions' => [
                'resume_draft' => $bid->status === ContractorBidStatus::Brouillon,
                'delete_draft' => $bid->status === ContractorBidStatus::Brouillon,
                'open_site' => $attributed,
            ],
        ];
    }

    /**
     * Conditions proposées par le promoteur, pour préremplir « Accepter les conditions » et « Contre-proposition ».
     *
     * @return array<string, mixed>
     */
    public function terms(Offer $offer): array
    {
        [$start, $end] = $this->offerPeriod($offer);
        $amount = $this->offers->conformingAmount($offer);

        return [
            'amount' => $amount,
            'promoter_amount' => (int) $offer->contract_total_amount,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'duration_days' => (int) $start->diffInDays($end) + 1,
            'schedule' => $this->schedule($offer, $amount, $start, (int) $start->diffInDays($end) + 1),
            'note' => 'Montant net, hors commission O’CHANTIER '.$this->pricing->value(PricingVariable::Y).' % payée par le promoteur.',
        ];
    }

    /**
     * Répartition indicative du prix net : acompte X à l’attribution, puis le solde selon le rythme de l’offre.
     *
     * @return list<array{label: string, amount: int, percent: float, due_date: string}>
     */
    public function schedule(Offer $offer, int $amount, CarbonImmutable|\DateTimeInterface $start, int $days): array
    {
        $start = CarbonImmutable::parse($start);
        $mode = $offer->payment_mode ?? ContractPaymentMode::Hebdomadaire;
        $x = $this->pricing->value(PricingVariable::X);
        $deposit = (int) round($amount * $x / 100);
        $count = $this->drafts->installments($mode, $days)['count'];
        $balance = $amount - $deposit;
        $lines = [['label' => 'Acompte à l’attribution', 'amount' => $deposit, 'percent' => $x, 'due_date' => $start->subDays(2)->max(CarbonImmutable::today())->toDateString()]];

        for ($k = 1; $k <= $count; $k++) {
            $part = $k === $count ? $balance - (int) (floor($balance / $count) * ($count - 1)) : (int) floor($balance / $count);
            $due = match ($mode) {
                ContractPaymentMode::Hebdomadaire => $start->addDays(min($days, 7 * $k) - 1),
                ContractPaymentMode::ParEtape => $start->addDays((int) round($days * $k / $count) - 1),
                ContractPaymentMode::FinDeChantier => $start->addDays($days - 1),
            };
            $label = match ($mode) {
                ContractPaymentMode::Hebdomadaire => 'Semaine '.$k.'/'.$count,
                ContractPaymentMode::ParEtape => 'Étape '.$k.'/'.$count,
                ContractPaymentMode::FinDeChantier => 'Solde à la réception',
            };
            $lines[] = ['label' => $label, 'amount' => $part, 'percent' => $amount > 0 ? round($part * 100 / $amount, 1) : 0.0, 'due_date' => $due->toDateString()];
        }

        return $lines;
    }

    /**
     * Début et fin des travaux proposés par le promoteur (sans fin : durée prévue du chantier, sinon 30 jours).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function offerPeriod(Offer $offer): array
    {
        $start = CarbonImmutable::parse($offer->start_date);
        $end = $offer->end_date
            ? CarbonImmutable::parse($offer->end_date)
            : ($offer->site->planned_end_date ? CarbonImmutable::parse($offer->site->planned_end_date) : $start->addDays(max(1, (int) ($offer->site->planned_duration_days ?: 30)) - 1));

        return [$start, $end->max($start)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allQuotes(Contractor $contractor, string $search): array
    {
        return collect(array_keys(ContractorQuoteProposals::TABS))
            ->flatMap(fn (string $tab) => $this->quotes->list($contractor, $tab, $search)['data'])
            ->map(fn (array $card) => [...$card, 'updated_at' => $card['sent_at']])
            ->all();
    }

    /**
     * @return array{value: string, label: string}
     */
    private function displayStatus(ContractorBid $bid): array
    {
        return match ($bid->status) {
            ContractorBidStatus::Retenue => ['value' => 'attribuee', 'label' => 'Attribuée'],
            ContractorBidStatus::Choisie => ['value' => 'acompte_en_attente', 'label' => 'Retenue · acompte en attente'],
            ContractorBidStatus::Proposee => ['value' => 'en_attente', 'label' => 'En attente'],
            default => ['value' => $bid->status->value, 'label' => $bid->status->label()],
        };
    }

    /**
     * Étapes « Suivi » : offre envoyée, choix du promoteur, acompte confirmé, chantier attribué.
     *
     * @return list<array{key: string, label: string, state: string, at: string|null}>
     */
    private function tracking(ContractorBid $bid): array
    {
        $status = $bid->status;
        $chosen = in_array($status, [ContractorBidStatus::Choisie, ContractorBidStatus::Retenue], true);
        $closed = in_array($status, [ContractorBidStatus::NonRetenue, ContractorBidStatus::Annulee], true);
        $attributed = $status === ContractorBidStatus::Retenue;
        $step = fn (string $key, string $label, string $state, mixed $at = null) => ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $at?->toIso8601String()];

        return [
            $step('sent', $bid->sent_at ? 'Offre envoyée' : 'Non envoyée', $bid->sent_at ? 'done' : 'current', $bid->sent_at),
            $step('decision', $closed ? 'Non retenue' : 'Choix du promoteur', $closed ? 'failed' : ($chosen ? 'done' : ($bid->sent_at ? 'current' : 'todo')), $bid->chosen_at),
            $step('deposit', 'Acompte confirmé', $attributed ? 'done' : ($status === ContractorBidStatus::Choisie ? 'current' : 'todo'), $attributed ? $bid->updated_at : null),
            $step('attributed', 'Chantier attribué', $attributed ? 'done' : 'todo', $attributed ? $bid->updated_at : null),
        ];
    }

    /**
     * @return array{name: string|null, phone: string|null}
     */
    private function promoter(Site $site): array
    {
        $user = $site->responsibleUser ?? $site->promoterProfile?->user;

        return [
            'name' => $user ? (trim($user->first_name.' '.$user->last_name) ?: null) : null,
            'phone' => $user ? PhoneNumber::of($user)?->formatted() : null,
        ];
    }
}
