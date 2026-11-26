<?php

namespace App\Support\Contractor;

use App\Enums\QuoteBidStatus;
use App\Enums\QuoteRequestStatus;
use App\Enums\QuoteVersionStatus;
use App\Enums\QuoteVisitConfirmation;
use App\Enums\QuoteVisitStatus;
use App\Models\Contractor;
use App\Models\QuoteBid;
use App\Models\QuoteRequestTrade;
use App\Models\QuoteVersion;
use App\Models\Site;
use App\Support\Media\MediaStore;
use App\Support\PhoneNumber;
use App\Support\Promoter\QuoteRequests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Propositions de devis d’un entrepreneur (« Proposer ma prestation », « Mes offres », « Proposition », mission de
 * visite et devis).
 *
 * La proposition est envoyée au promoteur avec les frais de service O’CHANTIER ; elle peut être retirée tant qu’il
 * n’a pas commandé. Commandée et payée, elle devient une mission de visite : l’adresse exacte et le contact du
 * promoteur sont alors communiqués. Après la visite, l’entrepreneur rédige le devis ; chaque version envoyée est
 * figée, une correction crée la version suivante.
 */
class ContractorQuoteProposals
{
    /**
     * Onglets de « Mes offres » => statuts de la proposition.
     *
     * @var array<string, array{label: string, statuses: list<QuoteBidStatus>}>
     */
    public const TABS = [
        'en_attente' => ['label' => 'En attente', 'statuses' => [QuoteBidStatus::Proposee]],
        'acceptees' => ['label' => 'Acceptées', 'statuses' => [QuoteBidStatus::Choisie]],
        'refusees' => ['label' => 'Refusées', 'statuses' => [QuoteBidStatus::NonRetenue]],
        'retirees' => ['label' => 'Retirées', 'statuses' => [QuoteBidStatus::Retiree]],
    ];

    private const WITH = [
        'quoteRequestTrade.trade', 'quoteRequestTrade.quoteRequest.site.commune.city', 'quoteRequestTrade.quoteRequest.site.neighborhood',
        'quoteRequestTrade.quoteRequest.site.mainImageMedia', 'quoteRequestTrade.quoteRequest.site.promoterProfile.user',
        'quoteRequestTrade.quoteRequest.site.responsibleUser', 'versions.document.documentType', 'versions.document.files',
    ];

    public function __construct(private QuoteRequests $quotes, private ContractorOpportunities $opportunities, private MediaStore $media) {}

    /**
     * « Vérifier et envoyer » : la proposition part au promoteur.
     *
     * @param  array{fee_amount: int, visit_date: string, visit_slot: string, visit_duration?: string|null, comment?: string|null}  $data
     */
    public function propose(Contractor $contractor, string $lineId, array $data): QuoteBid
    {
        $line = QuoteRequestTrade::query()->with('quoteRequest')->find($lineId);
        $detail = $line ? $this->opportunities->quote($contractor, $lineId) : null;

        if (! $line || ! $detail) {
            abort(404, 'Demande de devis introuvable.');
        }

        if (! $detail['response']['can_respond']) {
            throw ValidationException::withMessages(['proposal' => $detail['response']['reason']]);
        }

        $bid = $this->quotes->addBid($line, $contractor, (int) $data['fee_amount'], CarbonImmutable::parse($data['visit_date']), $data['visit_slot'], $data['visit_duration'] ?? null, $data['comment'] ?? null);

        return $this->find($contractor, $bid->id);
    }

    /**
     * Propositions de l’onglet, les plus récentes d’abord ; meta : compteurs par onglet.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(Contractor $contractor, string $tab, ?string $search = null): array
    {
        $all = $contractor->quoteBids()->with(self::WITH)->latest()->get();
        $search = mb_strtolower(trim((string) $search));
        $matches = fn (QuoteBid $bid) => $search === '' || str_contains(mb_strtolower($bid->quoteRequestTrade->quoteRequest->site->label), $search);

        return [
            'data' => $all->filter(fn (QuoteBid $bid) => in_array($bid->status, self::TABS[$tab]['statuses'], true) && $matches($bid))
                ->map(fn (QuoteBid $bid) => $this->card($bid))->values()->all(),
            'meta' => [
                'tab' => $tab,
                'tabs' => collect(self::TABS)->map(fn (array $definition, string $key) => [
                    'key' => $key,
                    'label' => $definition['label'],
                    'count' => $all->filter(fn (QuoteBid $bid) => in_array($bid->status, $definition['statuses'], true) && $matches($bid))->count(),
                ])->values()->all(),
            ],
        ];
    }

    public function find(Contractor $contractor, string $id): QuoteBid
    {
        $bid = str($id)->isUuid() ? $contractor->quoteBids()->with(self::WITH)->find($id) : null;

        abort_unless($bid, 404, 'Proposition introuvable.');

        return $bid;
    }

    public function findVersion(QuoteBid $bid, string $id): QuoteVersion
    {
        $version = str($id)->isUuid() ? $bid->versions()->find($id) : null;

        abort_unless($version, 404, 'Version de devis introuvable.');

        return $version;
    }

    /**
     * Carte de « Mes offres ».
     *
     * @return array<string, mixed>
     */
    public function card(QuoteBid $bid): array
    {
        $line = $bid->quoteRequestTrade;
        $request = $line->quoteRequest;
        $site = $request->site;

        return [
            'id' => $bid->id,
            'kind' => ['value' => 'quote_proposal', 'label' => 'Demande de devis'],
            'quote_id' => $line->id,
            'reference' => $request->reference,
            'trade' => $line->trade->only(['id', 'code', 'label']),
            'site' => [
                'id' => $site->id,
                'reference' => $site->reference,
                'title' => $site->label,
                'place' => collect([$site->neighborhood?->name ?? $site->commune?->name, $site->commune?->city?->name])->filter()->unique()->implode(', '),
                'image_url' => $site->mainImageMedia ? $this->media->temporaryUrl($site->mainImageMedia) : null,
            ],
            'amount' => $bid->amount,
            'status' => ['value' => $bid->status->value, 'label' => $bid->status->label()],
            'display_status' => $this->displayStatus($bid),
            'sent_at' => $bid->created_at?->toIso8601String(),
        ];
    }

    /**
     * Écran « Proposition » : montants, suivi, mission de visite, devis et actions possibles.
     *
     * @return array<string, mixed>
     */
    public function detail(QuoteBid $bid): array
    {
        $line = $bid->quoteRequestTrade;
        $request = $line->quoteRequest;
        $site = $request->site;
        $ordered = $bid->status === QuoteBidStatus::Choisie;
        $versions = $bid->versions->sortBy('version')->values();
        $last = $versions->last();
        $sent = $versions->filter(fn (QuoteVersion $version) => $version->status !== QuoteVersionStatus::Brouillon);

        return [
            ...$this->card($bid),
            'money' => [
                'fee_amount' => $bid->fee_amount,
                'service_fee_percent' => $bid->service_fee_percent,
                'service_fee_amount' => $bid->service_fee_amount,
                'total_amount' => $bid->amount,
                'net_amount' => $bid->fee_amount,
                'paid' => $ordered,
            ],
            'visit_request' => [
                'date' => $bid->visit_date->toDateString(),
                'slot' => $bid->visit_slot,
                'duration' => $bid->visit_duration,
                'comment' => $bid->comment,
            ],
            'tracking' => $this->tracking($bid, $request->ordered_at, $sent),
            'mission' => $ordered ? [
                ...($this->quotes->visit($bid) ?? []),
                'address' => $site->address,
                'location' => $site->location,
                'access_instructions' => $site->access_instructions,
                'promoter' => $this->promoter($site),
                'instructions' => 'Présentez-vous avec votre pièce d’identité. Photos et relevés autorisés pour l’établissement du devis.',
            ] : null,
            'versions' => $versions->map(fn (QuoteVersion $version) => $this->quotes->version($version))->all(),
            'notice' => match (true) {
                $bid->status === QuoteBidStatus::NonRetenue && $request->status === QuoteRequestStatus::Annule => 'Le promoteur a annulé sa demande de devis. Cette fiche reste consultable.',
                $bid->status === QuoteBidStatus::NonRetenue => 'Le promoteur a retenu une autre proposition. Cette fiche reste consultable.',
                $bid->status === QuoteBidStatus::Retiree => 'Cette proposition n’est plus active. Elle reste consultable.',
                $bid->status === QuoteBidStatus::Proposee => 'Le promoteur compare les meilleures propositions de chaque métier. La mission démarre après le paiement de sa commande.',
                $ordered && $bid->visit_confirmation === QuoteVisitConfirmation::Contestee => 'Le promoteur indique que la visite n’a pas eu lieu. Vos frais de visite sont suspendus le temps que O’CHANTIER examine la situation.',
                $ordered && $bid->visit_status === QuoteVisitStatus::Realisee && $bid->visit_confirmation === null => 'Visite déclarée : le promoteur a jusqu’au '.$this->quotes->confirmationDeadline($bid)->format('d/m/Y').' pour la confirmer. Sans réponse, elle sera confirmée et vos frais de visite deviendront dus.',
                default => null,
            },
            'actions' => [
                'withdraw' => $bid->status === QuoteBidStatus::Proposee && $request->status === QuoteRequestStatus::Recherche,
                'mark_visited' => $ordered && $bid->visit_status === QuoteVisitStatus::Planifiee,
                'request_reschedule' => $ordered && $bid->visit_status === QuoteVisitStatus::Planifiee,
                'create_quote' => $ordered && $bid->visit_status === QuoteVisitStatus::Realisee && $last?->status !== QuoteVersionStatus::Brouillon,
                'edit_draft_id' => $last?->status === QuoteVersionStatus::Brouillon ? $last->id : null,
            ],
            'options' => ['slots' => QuoteRequests::SLOTS, 'durations' => QuoteRequests::DURATIONS, 'units' => ['u', 'm', 'm²', 'ml', 'm³', 'h', 'jour', 'forfait'], 'validity_days' => [15, 30, 60, 90], 'vat_rate' => QuoteRequests::DEFAULT_VAT_RATE],
        ];
    }

    /**
     * Étapes « Suivi » : envoyée, décision, paiement, visite, devis remis.
     *
     * @param  Collection<int, QuoteVersion>  $sent
     * @return list<array{key: string, label: string, state: string, at: string|null}>
     */
    private function tracking(QuoteBid $bid, mixed $orderedAt, Collection $sent): array
    {
        $ordered = $bid->status === QuoteBidStatus::Choisie;
        $closed = in_array($bid->status, [QuoteBidStatus::NonRetenue, QuoteBidStatus::Retiree], true);
        $visited = $bid->visit_status === QuoteVisitStatus::Realisee;
        $step = fn (string $key, string $label, string $state, mixed $at = null) => ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $at?->toIso8601String()];

        return [
            $step('sent', 'Proposition envoyée', 'done', $bid->created_at),
            $step('decision', match ($bid->status) {
                QuoteBidStatus::NonRetenue => 'Non retenue',
                QuoteBidStatus::Retiree => 'Retirée',
                QuoteBidStatus::Choisie => 'Acceptée par le promoteur',
                default => 'Décision du promoteur',
            }, $closed ? 'failed' : ($ordered ? 'done' : 'current'), $bid->status === QuoteBidStatus::Retiree ? $bid->withdrawn_at : ($ordered ? $orderedAt : null)),
            $step('payment', 'Paiement confirmé', $ordered ? 'done' : 'todo', $ordered ? $orderedAt : null),
            $step('visit', 'Visite réalisée', $visited ? 'done' : ($ordered ? 'current' : 'todo'), $bid->visited_at),
            $step('quote', $sent->isNotEmpty() ? 'Devis remis (v'.$sent->max('version').')' : 'Devis remis', $sent->isNotEmpty() ? 'done' : ($visited ? 'current' : 'todo'), $sent->max('sent_at')),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private function displayStatus(QuoteBid $bid): array
    {
        if ($bid->status !== QuoteBidStatus::Choisie) {
            return ['value' => $bid->status->value, 'label' => $bid->status === QuoteBidStatus::Proposee ? 'En attente' : $bid->status->label()];
        }

        $sent = $bid->versions->filter(fn (QuoteVersion $version) => $version->status !== QuoteVersionStatus::Brouillon);

        return match (true) {
            $sent->contains(fn (QuoteVersion $version) => $version->status === QuoteVersionStatus::Recu) => ['value' => 'devis_recu', 'label' => 'Devis reçu par le promoteur'],
            $sent->isNotEmpty() => ['value' => 'devis_envoye', 'label' => 'Devis envoyé'],
            $bid->visit_status === QuoteVisitStatus::Realisee => ['value' => 'devis_a_rediger', 'label' => 'Devis à rédiger'],
            $bid->visit_status === QuoteVisitStatus::ReportPropose => ['value' => 'report_propose', 'label' => 'Report proposé'],
            default => ['value' => 'visite_planifiee', 'label' => 'Acceptée · payée'],
        };
    }

    /**
     * Contact du promoteur pour la visite : responsable du chantier, sinon titulaire du compte.
     *
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
