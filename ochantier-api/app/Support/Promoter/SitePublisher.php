<?php

namespace App\Support\Promoter;

use App\Enums\ContractPaymentMode;
use App\Enums\NeedStatus;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Enums\OfferVisibility;
use App\Enums\PaymentFrequency;
use App\Enums\PriceOrigin;
use App\Enums\SiteStatus;
use App\Models\Offer;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Support\Pricing\PricingRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publication d’un chantier (« Publier ») : crée l’offre et ses besoins à partir du brouillon et les transmet au
 * Back office pour contrôle, avant la recherche d’ouvriers (matching).
 */
class SitePublisher
{
    /**
     * Au-delà de cette durée, une offre journalière est « temporaire » plutôt que « ponctuelle ».
     */
    public const PONCTUAL_MAX_DAYS = 30;

    public function __construct(private OfferDraft $drafts, private SiteManager $sites, private WorkerCatalog $catalog, private PricingRules $pricing) {}

    public function publish(Site $site, User $author): Offer
    {
        $this->sites->ensureEditable($site);

        if ($errors = $this->drafts->publicationErrors($site)) {
            throw ValidationException::withMessages(['publication' => array_column($errors, 'label')]);
        }

        // Le circuit de la demande de devis (techniciens de l’application Ouvriers) n’est pas encore en place.
        if ($this->drafts->requestsQuote($site)) {
            throw ValidationException::withMessages(['publication' => ['La demande de devis n’est pas encore disponible. Joignez votre devis pour publier.']]);
        }

        return DB::transaction(function () use ($site, $author): Offer {
            $contract = $this->drafts->type($site) === OfferDraft::CONTRACT;
            $type = match (true) {
                $contract => OfferType::Contractuelle,
                $site->planned_duration_days > self::PONCTUAL_MAX_DAYS => OfferType::Temporaire,
                default => OfferType::Ponctuelle,
            };
            $amount = $contract ? (int) $site->offer_draft['contract']['amount'] : null;
            $mode = $contract ? $this->drafts->paymentMode($site) : null;

            $offer = $site->offers()->create([
                'reference' => $this->reference('OF', 'offer_reference_seq'),
                'offer_type' => $type,
                'price_origin' => $contract ? PriceOrigin::Promoteur : PriceOrigin::Ouvrier,
                'title' => $site->label,
                'description' => $site->description,
                'start_date' => $site->planned_start_date,
                'end_date' => $site->planned_end_date,
                'contract_total_amount' => $amount,
                'quote_document_id' => $contract ? $this->drafts->quotes($site)->first()?->getKey() : null,
                'payment_mode' => $mode,
                'visibility' => OfferVisibility::Ciblee,
                'status' => OfferStatus::Soumise,
                'submitted_at' => now(),
                'created_by_user_id' => $author->getKey(),
            ]);
            $this->history($offer, null, OfferStatus::Soumise->value, $author);
            $offer->refresh();

            if ($contract) {
                $this->createMilestones($offer, $site, $amount, $mode);
            } else {
                $this->createNeeds($offer, $site, $author, $type, $site->offer_draft);
            }

            $site->forceFill(['status' => SiteStatus::Soumis, 'submitted_at' => now(), 'lock_version' => $site->lock_version + 1])->save();
            $site->statusHistories()->create(['from_status' => SiteStatus::Brouillon, 'to_status' => SiteStatus::Soumis, 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);

            return $offer;
        });
    }

    /**
     * Nouvelle réservation sur un chantier déjà publié : nouvelle offre journalière et ses besoins, transmis au Back
     * office. Sans dates, la réservation reprend celles du chantier.
     *
     * @param  array<string, mixed>  $data  needs, worker_ids, start_date, end_date (ReservationRequest).
     */
    public function publishAdditional(Site $site, User $author, array $data): Offer
    {
        $start = CarbonImmutable::parse($data['start_date'] ?? $site->planned_start_date);
        $end = CarbonImmutable::parse($data['end_date'] ?? $site->planned_end_date);
        $lines = $this->drafts->dailyLines($data);
        $errors = [];

        foreach ($lines as $line) {
            if ($line['headcount'] > 0 && ! $line['daily_rate']) {
                $errors[] = 'Proposez un tarif pour « '.$line['trade']['label'].' »';
            }
        }

        if (array_sum(array_column($lines, 'total_headcount')) === 0) {
            $errors[] = 'Ajoutez un besoin ou choisissez des ouvriers';
        }

        if ($start->isPast() && ! $start->isToday()) {
            $errors[] = 'Choisissez des dates de mission valides';
        }

        if ($errors) {
            throw ValidationException::withMessages(['reservation' => $errors]);
        }

        return DB::transaction(function () use ($site, $author, $data, $start, $end): Offer {
            $days = (int) $start->diffInDays($end) + 1;
            $type = $days > self::PONCTUAL_MAX_DAYS ? OfferType::Temporaire : OfferType::Ponctuelle;

            $offer = $site->offers()->create([
                'reference' => $this->reference('OF', 'offer_reference_seq'),
                'offer_type' => $type,
                'price_origin' => PriceOrigin::Ouvrier,
                'title' => $site->label,
                'description' => $site->description,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'visibility' => OfferVisibility::Ciblee,
                'status' => OfferStatus::Soumise,
                'submitted_at' => now(),
                'created_by_user_id' => $author->getKey(),
            ]);
            $this->history($offer, null, OfferStatus::Soumise->value, $author);
            $this->createNeeds($offer->refresh(), $site, $author, $type, $data);

            return $offer;
        });
    }

    /**
     * Un besoin par métier : effectif à rechercher plus ouvriers choisis directement, tarif journalier proposé.
     */
    /**
     * @param  array<string, mixed>  $draft  Besoins saisis (needs, worker_ids).
     */
    private function createNeeds(Offer $offer, Site $site, User $author, OfferType $type, array $draft): void
    {
        $workers = $this->drafts->requestedWorkers($draft)->keyBy('id');
        $days = (int) $offer->start_date->diffInDays($offer->end_date) + 1;

        foreach ($this->drafts->dailyLines($draft) as $line) {
            $headcount = $line['total_headcount'];
            $label = $line['trade']['label'];
            $chosenRates = array_filter(array_column($line['requested_workers'], 'daily_rate'));

            $need = $offer->needs()->create([
                'reference' => $this->reference('BS', 'need_reference_seq'),
                'site_id' => $site->getKey(),
                'offer_type' => $type,
                'trade_id' => $line['trade']['id'],
                'headcount' => $headcount,
                'title' => $label.($headcount > 1 ? ' × '.$headcount : ''),
                'description' => $site->description ?: 'Recherche de '.$headcount.' '.mb_strtolower($label).' pour « '.$site->label.' ».',
                'start_date' => $offer->start_date,
                'end_date' => $offer->end_date,
                'duration_days' => $days,
                'payment_frequency' => PaymentFrequency::Hebdomadaire,
                'indicative_daily_budget' => $line['daily_rate'] ?: ($chosenRates ? (int) round(array_sum($chosenRates) / count($chosenRates)) : null),
                'status' => NeedStatus::Soumis,
                'submitted_at' => now(),
                'created_by_user_id' => $author->getKey(),
            ]);
            $need->statusHistories()->create(['from_status' => null, 'to_status' => NeedStatus::Soumis->value, 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);

            foreach ($line['requested_workers'] as $requested) {
                /** @var Worker $worker */
                $worker = $workers->get($requested['id']);
                $need->requestedWorkers()->create([
                    'worker_id' => $worker->getKey(),
                    'promoter_daily_rate' => $requested['daily_rate'],
                    'requested_by_user_id' => $author->getKey(),
                ]);
            }
        }
    }

    /**
     * Échéances du solde d’une offre contractuelle payée par étape (le paiement hebdomadaire est calculé avec
     * l’échéancier ; « Fin de chantier » n’est plus proposé à la publication).
     */
    private function createMilestones(Offer $offer, Site $site, int $amount, ContractPaymentMode $mode): void
    {
        if ($mode === ContractPaymentMode::Hebdomadaire) {
            return;
        }

        $balance = $amount - $this->pricing->deposit($amount);
        $count = $this->drafts->installments($mode, $site->planned_duration_days)['count'];
        $start = CarbonImmutable::parse($site->planned_start_date);
        $base = (int) (round($balance / $count / 1000) * 1000);

        for ($k = 1; $k <= $count; $k++) {
            $offer->milestones()->create([
                'sequence' => $k,
                'label' => $mode === ContractPaymentMode::FinDeChantier ? 'Fin de chantier' : 'Étape '.$k.'/'.$count,
                'amount' => $k < $count ? $base : $balance - $base * ($count - 1),
                'due_date' => $start->addDays((int) round($site->planned_duration_days * $k / $count) - 1),
            ]);
        }
    }

    private function history(Offer $offer, ?string $from, string $to, User $author): void
    {
        $offer->statusHistories()->create(['from_status' => $from, 'to_status' => $to, 'actor_type' => $author->getMorphClass(), 'actor_id' => $author->getKey()]);
    }

    private function reference(string $prefix, string $sequence): string
    {
        return sprintf('%s-%s-%04d', $prefix, now()->format('Y'), DB::scalar("SELECT nextval('{$sequence}')"));
    }
}
