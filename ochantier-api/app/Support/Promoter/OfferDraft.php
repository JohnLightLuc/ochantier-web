<?php

namespace App\Support\Promoter;

use App\Enums\ContractPaymentMode;
use App\Enums\PricingVariable;
use App\Http\Resources\V1\DocumentResource;
use App\Models\Document;
use App\Models\Site;
use App\Models\Trade;
use App\Models\Worker;
use App\Models\WorkerTrade;
use App\Support\Documents\DocumentStore;
use App\Support\Pricing\PricingRules;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Besoins d’un chantier saisis avant la publication (« Offre journalière » ou « Offre contractuelle »).
 *
 * Offre journalière : effectifs par métier avec le tarif journalier proposé, et/ou ouvriers choisis directement.
 * Offre contractuelle : « Avez-vous un devis ? ». Oui : devis du promoteur (PDF ou image), forfait global et
 * paiement du solde (hebdomadaire ou par étape) ; les entreprises répondent au forfait. Non : demande de devis par
 * corps de métier, sans offre financière ; un technicien établit le devis avant que l’offre soit définie.
 *
 * Le brouillon est enregistré sur le chantier (sites.offer_draft) ; la publication en crée l’offre et les besoins.
 */
class OfferDraft
{
    public const DAILY = 'journaliere';

    public const CONTRACT = 'contractuelle';

    /**
     * Sous ce pourcentage du tarif de référence, le tarif proposé est signalé comme bas.
     */
    public const LOW_RATE_PERCENT = 70;

    /**
     * Pièce du chantier qui sert de devis à l’offre contractuelle (« Télécharger le devis »).
     */
    public const QUOTE_DOCUMENT = 'site_quote';

    /**
     * Paiements du solde proposés (« Fin de chantier » n’est plus proposé ; il reste lisible sur les offres
     * publiées avant).
     *
     * @var list<ContractPaymentMode>
     */
    public const PAYMENT_MODES = [ContractPaymentMode::Hebdomadaire, ContractPaymentMode::ParEtape];

    public function __construct(private PricingRules $pricing, private WorkerCatalog $catalog, private DocumentStore $documents) {}

    /**
     * Remplace les besoins du brouillon par ceux du formulaire (seuls ceux du type d’offre choisi sont conservés).
     *
     * @param  array<string, mixed>  $data  Champs validés (OfferDraftRequest).
     */
    public function save(Site $site, array $data): Site
    {
        if (isset($data['lock_version']) && (int) $data['lock_version'] !== $site->lock_version) {
            throw new ConflictHttpException('Ce chantier a été modifié sur un autre appareil. Rechargez-le avant de continuer.');
        }

        $draft = $data['type'] === self::CONTRACT
            ? ['type' => self::CONTRACT, 'contract' => [
                'has_quote' => isset($data['contract']['has_quote']) ? (bool) $data['contract']['has_quote'] : null,
                'amount' => isset($data['contract']['amount']) ? (int) $data['contract']['amount'] : null,
                'payment_mode' => $data['contract']['payment_mode'] ?? ContractPaymentMode::Hebdomadaire->value,
                'quote_request' => [
                    'trade_ids' => array_values(array_unique($data['contract']['quote_request']['trade_ids'] ?? [])),
                    'notes' => filled($data['contract']['quote_request']['notes'] ?? null) ? trim($data['contract']['quote_request']['notes']) : null,
                ],
            ]]
            : ['type' => self::DAILY, 'needs' => collect($data['needs'] ?? [])
                ->filter(fn (array $need) => (int) ($need['headcount'] ?? 0) > 0 || filled($need['daily_rate'] ?? null))
                ->map(fn (array $need) => [
                    'trade_id' => $need['trade_id'],
                    'headcount' => (int) ($need['headcount'] ?? 0),
                    'daily_rate' => isset($need['daily_rate']) ? (int) $need['daily_rate'] : null,
                ])->values()->all(),
                'worker_ids' => array_values(array_unique($data['worker_ids'] ?? [])),
            ];

        $site->forceFill(['offer_draft' => $draft, 'lock_version' => $site->lock_version + 1])->save();

        return $site->refresh();
    }

    public function type(Site $site): ?string
    {
        return $site->offer_draft['type'] ?? null;
    }

    /**
     * Réponse à « Avez-vous un devis ? » (null tant que le promoteur n’a pas répondu).
     */
    public function hasQuote(Site $site): ?bool
    {
        return $site->offer_draft['contract']['has_quote'] ?? null;
    }

    /**
     * Offre contractuelle sans devis : le promoteur demande un devis au lieu de publier une offre financière.
     */
    public function requestsQuote(Site $site): bool
    {
        return $this->type($site) === self::CONTRACT && $this->hasQuote($site) === false;
    }

    /**
     * Devis joints par le promoteur, du plus récent au plus ancien.
     *
     * @return Collection<int, Document>
     */
    public function quotes(Site $site): Collection
    {
        return $this->documents->active($site)
            ->filter(fn (Document $document) => $document->documentType?->code === self::QUOTE_DOCUMENT)
            ->values();
    }

    /**
     * Corps de métier de la demande de devis encore proposés, dans l’ordre du référentiel.
     *
     * @return Collection<int, Trade>
     */
    public function quoteTrades(Site $site): Collection
    {
        $ids = $site->offer_draft['contract']['quote_request']['trade_ids'] ?? [];

        return $ids ? Trade::query()->whereKey($ids)->where('is_active', true)->where('accepts_quotes', true)->orderBy('sort_order')->get() : collect();
    }

    /**
     * Récapitulatif affiché sous le formulaire et dans le détail du chantier.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Site $site): ?array
    {
        return match ($this->type($site)) {
            self::DAILY => $this->dailySummary($site),
            self::CONTRACT => $this->contractSummary($site),
            default => null,
        };
    }

    /**
     * Ouvriers choisis encore validés, avec leur métier principal et leur tarif.
     *
     * @return Collection<int, Worker>
     */
    public function requestedWorkers(Site|array $draft): Collection
    {
        return $this->catalog->find(($draft instanceof Site ? $draft->offer_draft : $draft)['worker_ids'] ?? []);
    }

    /**
     * Lignes de l’offre journalière par métier : effectif à rechercher, ouvriers choisis, tarif proposé.
     *
     * @param  Site|array<string, mixed>  $draft  Chantier (brouillon enregistré) ou besoins saisis (needs, worker_ids).
     * @return list<array<string, mixed>>
     */
    public function dailyLines(Site|array $draft): array
    {
        $draft = $draft instanceof Site ? ($draft->offer_draft ?? []) : $draft;
        $references = $this->pricing->referenceRates();
        $workers = $this->requestedWorkers($draft);
        $byTrade = $workers->groupBy(fn (Worker $worker) => $this->primaryTradeId($worker));
        $needs = collect($draft['needs'] ?? [])->map(fn (array $need) => ['trade_id' => $need['trade_id'], 'headcount' => (int) ($need['headcount'] ?? 0), 'daily_rate' => isset($need['daily_rate']) ? (int) $need['daily_rate'] : null])->keyBy('trade_id');
        $tradeIds = $needs->keys()->concat($byTrade->keys())->filter()->unique()->values();
        $trades = Trade::query()->whereKey($tradeIds)->get()->keyBy('id');

        return $tradeIds->map(function (string $tradeId) use ($needs, $byTrade, $trades, $references): array {
            $need = $needs->get($tradeId, ['headcount' => 0, 'daily_rate' => null]);
            $chosen = $byTrade->get($tradeId, collect());
            $reference = $references->get($tradeId)['rate'] ?? null;
            $rate = $need['daily_rate'];
            $chosenCost = $chosen->sum(fn (Worker $worker) => $this->catalog->promoterRate($worker) ?? 0);

            return [
                'trade' => $trades->get($tradeId)?->only(['id', 'code', 'label']),
                'headcount' => $need['headcount'],
                'requested_workers' => $chosen->map(fn (Worker $worker) => [
                    'id' => $worker->id,
                    'name' => trim($worker->first_name.' '.$worker->last_name),
                    'daily_rate' => $this->catalog->promoterRate($worker),
                ])->values()->all(),
                'total_headcount' => $need['headcount'] + $chosen->count(),
                'daily_rate' => $rate,
                'reference_rate' => $reference,
                'rate_hint' => $this->rateHint($need['headcount'], $rate, $reference),
                'daily_cost' => $need['headcount'] * ($rate ?? $reference ?? 0) + $chosenCost,
            ];
        })->all();
    }

    /**
     * Éléments manquants avant la publication, dans l’ordre des messages de la maquette.
     *
     * @return list<array{key: string, label: string}>
     */
    public function publicationErrors(Site $site): array
    {
        $type = $this->type($site);
        $errors = [];
        $add = function (string $key, string $label) use (&$errors): void {
            $errors[] = compact('key', 'label');
        };

        // Demande de devis : un titre, une localisation et au moins un corps de métier suffisent.
        if ($this->requestsQuote($site)) {
            if (blank($site->label)) {
                $add('title', 'Donnez un titre au chantier');
            }

            if (blank($site->address)) {
                $add('location', 'Indiquez la localisation');
            }

            if ($this->quoteTrades($site)->isEmpty()) {
                $add('quote_trades', 'Choisissez au moins un corps de métier pour le devis');
            }

            return $errors;
        }

        if ($type === self::DAILY) {
            foreach ($this->dailyLines($site) as $line) {
                if ($line['headcount'] > 0 && ! $line['daily_rate']) {
                    $add('rates', 'Proposez un tarif journalier pour « '.$line['trade']['label'].' »');
                }
            }
        }

        if (! $site->main_image_media_id) {
            $add('photo', 'Ajoutez une photo du chantier');
        }

        if (blank($site->label)) {
            $add('title', 'Donnez un titre au chantier');
        }

        if (blank($site->address)) {
            $add('location', 'Indiquez la localisation');
        }

        if (! $site->planned_start_date || ! $site->planned_end_date) {
            $add('dates', 'Renseignez les dates de début et de fin');
        }

        if ($type === self::CONTRACT) {
            if ($this->hasQuote($site) === null) {
                $add('has_quote', 'Indiquez si vous avez un devis');
            } else {
                if ($this->quotes($site)->isEmpty()) {
                    $add('quote', 'Téléchargez votre devis');
                }

                if (! ($site->offer_draft['contract']['amount'] ?? null)) {
                    $add('amount', 'Indiquez le montant de votre offre financière');
                }

                if (! in_array($this->paymentMode($site), self::PAYMENT_MODES, true)) {
                    $add('payment_mode', 'Choisissez le paiement du solde : hebdomadaire ou par étape');
                }
            }
        } elseif (collect($type ? $this->dailyLines($site) : [])->sum('total_headcount') === 0) {
            $add('needs', 'Ajoutez des besoins en ouvriers ou sélectionnez des ouvriers');
        }

        return $errors;
    }

    /**
     * Acompte et solde d’une offre contractuelle (« Acompte à la confirmation (30 %) », « Solde · 4 échéances
     * hebdomadaires »).
     *
     * @return array{count: int, label: string}
     */
    public function installments(ContractPaymentMode $mode, ?int $days): array
    {
        $weeks = max(1, (int) ceil(($days ?: 30) / 7));

        return match ($mode) {
            ContractPaymentMode::Hebdomadaire => ['count' => $weeks, 'label' => $weeks.' échéance'.($weeks > 1 ? 's hebdomadaires' : ' hebdomadaire')],
            ContractPaymentMode::ParEtape => ['count' => 3, 'label' => '3 échéances par étape'],
            ContractPaymentMode::FinDeChantier => ['count' => 1, 'label' => '1 paiement à la fin'],
        };
    }

    public function paymentMode(Site $site): ContractPaymentMode
    {
        return ContractPaymentMode::tryFrom($site->offer_draft['contract']['payment_mode'] ?? '') ?? ContractPaymentMode::Hebdomadaire;
    }

    public function primaryTradeId(Worker $worker): ?string
    {
        /** @var WorkerTrade|null $primary */
        $primary = $worker->workerTrades->firstWhere('is_primary', true) ?? $worker->workerTrades->first();

        return $primary?->trade_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function dailySummary(Site $site): array
    {
        $lines = $this->dailyLines($site);
        $toSearch = array_sum(array_column($lines, 'headcount'));
        $chosen = array_sum(array_map(fn (array $line) => count($line['requested_workers']), $lines));
        $daily = array_sum(array_column($lines, 'daily_cost'));
        $days = $site->planned_duration_days;
        $estimate = $days ? $daily * $days : null;
        $prices = $estimate ? $this->pricing->breakdown($estimate) : null;

        return [
            'type' => ['value' => self::DAILY, 'label' => 'Offre journalière'],
            'lines' => $lines,
            'workers_count' => $toSearch + $chosen,
            'to_search_count' => $toSearch,
            'chosen_count' => $chosen,
            'daily_cost' => $daily,
            'days' => $days,
            'estimate' => $estimate,
            'commission_percent' => $this->pricing->value(PricingVariable::Y),
            'commission_estimate' => $prices['commission'] ?? null,
            'total_estimate' => $prices['total'] ?? null,
            'deposit_percent' => $this->pricing->value(PricingVariable::X),
            'deposit_estimate' => $prices['deposit'] ?? null,
            'publish_hint' => $toSearch === 0 && $chosen > 0
                ? 'Les ouvriers choisis reçoivent votre demande directement.'
                : 'Les ouvriers disponibles du métier sont notifiés de votre offre.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contractSummary(Site $site): array
    {
        $amount = $site->offer_draft['contract']['amount'] ?? null;
        $mode = $this->paymentMode($site);
        $days = $site->planned_duration_days;
        $deposit = $amount ? $this->pricing->deposit($amount) : null;
        $documents = $this->documents->active($site)->count();
        $hasQuote = $this->hasQuote($site);

        return [
            'type' => ['value' => self::CONTRACT, 'label' => 'Offre contractuelle'],
            'has_quote' => $hasQuote,
            'quotes' => DocumentResource::collection($this->quotes($site))->resolve(),
            'quote_request' => [
                'trades' => $this->quoteTrades($site)->map(fn (Trade $trade) => $trade->only(['id', 'code', 'label']))->values()->all(),
                'notes' => $site->offer_draft['contract']['quote_request']['notes'] ?? null,
            ],
            'submit_label' => $hasQuote === false ? 'Rechercher un technicien' : 'Publier',
            'amount' => $amount,
            'payment_mode' => ['value' => $mode->value, 'label' => $mode->label()],
            'deposit_percent' => $this->pricing->value(PricingVariable::X),
            'deposit' => $deposit,
            'balance' => $amount ? $amount - $deposit : null,
            'installments' => $this->installments($mode, $days),
            'days' => $days,
            'daily_equivalent' => $amount && $days ? (int) round($amount / $days) : null,
            'documents_count' => $documents,
            'documents_hint' => $documents
                ? $documents.' document'.($documents > 1 ? 's' : '').' joint'.($documents > 1 ? 's' : '').' à l’offre'
                : 'Joignez vos plans et le cahier des charges (facultatif)',
        ];
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function rateHint(int $headcount, ?int $rate, ?int $reference): ?array
    {
        return match (true) {
            $headcount === 0 => null,
            ! $rate => ['value' => 'manquant', 'label' => 'Proposez un tarif pour ce métier'],
            $reference && $rate < $reference * self::LOW_RATE_PERCENT / 100 => ['value' => 'bas', 'label' => 'Tarif bas : moins d’ouvriers risquent d’accepter'],
            default => ['value' => 'ok', 'label' => 'Tarif proposé aux ouvriers'],
        };
    }
}
