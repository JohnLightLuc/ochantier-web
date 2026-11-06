<?php

namespace App\Support\Promoter;

use App\Enums\AssignmentStatus;
use App\Enums\MissionStatus;
use App\Enums\OfferType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundStatus;
use App\Models\Assignment;
use App\Models\Mission;
use App\Models\PaymentTransaction;
use App\Models\Receipt;
use App\Models\Site;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * « Coût du chantier », « Factures » du détail chantier et bilan financier du rapport de fin de chantier. Les
 * factures sont les reçus émis à chaque paiement Mobile Money réussi du chantier.
 */
class SiteInvoices
{
    /**
     * Missions engagées : acompte payé, pas annulées.
     */
    private const ENGAGED_MISSIONS = [MissionStatus::Confirmee, MissionStatus::EnCours, MissionStatus::Suspendue, MissionStatus::Terminee];

    public function __construct(private MediaStore $media, private PromoterPayments $payments) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Site $site): array
    {
        $invoices = $this->receipts($site);
        $total = (int) $invoices->sum('amount');

        return [
            'cost' => $this->cost($site),
            'invoices' => $invoices->map(fn (Receipt $receipt) => $this->invoice($receipt))->values()->all(),
            'count' => $invoices->count(),
            'count_label' => $invoices->isEmpty() ? 'Aucune facture' : $invoices->count().' facture'.($invoices->count() > 1 ? 's' : ''),
            'total' => $total,
            'total_label' => 'Total réglé, frais inclus : '.$this->payments->money($total),
        ];
    }

    /**
     * « Main-d’œuvre journalière », « Forfaits contractuels » et « Coût prévisionnel » des réservations engagées.
     *
     * @return array<string, mixed>
     */
    public function cost(Site $site): array
    {
        $missions = $this->missions($site);
        $daily = (int) Assignment::query()
            ->whereIn('mission_id', $missions->modelKeys())
            ->whereIn('status', [AssignmentStatus::Confirmee, AssignmentStatus::Active])
            ->sum('promoter_daily_rate');
        $contract = (int) $missions
            ->filter(fn (Mission $mission) => $mission->pricingSnapshot->offer_type === OfferType::Contractuelle)
            ->sum(fn (Mission $mission) => $mission->pricingSnapshot->total_promoter_amount);
        $forecast = (int) $missions->sum(fn (Mission $mission) => $mission->pricingSnapshot->total_promoter_amount);

        return [
            'daily_labor' => $daily,
            'daily_labor_label' => $this->payments->money($daily).' / jour',
            'contract_amount' => $contract,
            'contract_amount_label' => $this->payments->money($contract),
            'forecast_total' => $forecast,
            'forecast_total_label' => $this->payments->money($forecast),
        ];
    }

    /**
     * « Bilan financier » : montant des travaux, déjà réglé, frais et reste à payer.
     *
     * @return array{lines: list<array{label: string, amount: int}>, total_disbursed: int, total_disbursed_label: string}
     */
    public function balance(Site $site): array
    {
        $missions = $this->missions($site);
        $works = (int) $missions->sum(fn (Mission $mission) => $mission->billedAmount());
        $commission = (int) $missions->sum(fn (Mission $mission) => $mission->billedCommission());
        $refunded = (int) $missions->sum(fn (Mission $mission) => $mission->refunds->where('status', RefundStatus::Execute)->sum('amount'));
        $paid = (int) $missions->sum(fn (Mission $mission) => $mission->paymentSchedules->sum('amount_paid')) - $refunded;
        $fees = (int) $this->transactions($site)->sum('fees_amount');

        return [
            'lines' => [
                ['label' => 'Montant des travaux', 'amount' => $works],
                ['label' => 'Dont commission O’CHANTIER', 'amount' => $commission],
                ['label' => 'Montant réglé', 'amount' => $paid],
                ...($refunded > 0 ? [['label' => 'Dont remboursé', 'amount' => $refunded]] : []),
                ['label' => 'Frais opérateurs', 'amount' => $fees],
                ['label' => 'Reste à payer', 'amount' => max(0, $works - $paid)],
            ],
            'total_disbursed' => $paid + $fees,
            'total_disbursed_label' => $this->payments->money($paid + $fees),
        ];
    }

    /**
     * @return Collection<int, Mission>
     */
    private function missions(Site $site): Collection
    {
        return $site->missions()->whereIn('status', self::ENGAGED_MISSIONS)->with(['pricingSnapshot', 'paymentSchedules', 'refunds'])->get();
    }

    /**
     * @return Collection<int, PaymentTransaction>
     */
    private function transactions(Site $site): Collection
    {
        return PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::Reussie)
            ->where('payer_type', $site->promoterProfile->getMorphClass())
            ->where('payer_id', $site->promoter_profile_id)
            ->where('metadata->site_id', $site->getKey())
            ->get();
    }

    /**
     * @return Collection<int, Receipt>
     */
    private function receipts(Site $site): Collection
    {
        return Receipt::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->getKey())
            ->where('issued_to_type', $site->promoterProfile->getMorphClass())
            ->where('issued_to_id', $site->promoter_profile_id)
            ->with(['transaction.operator', 'mediaFile'])
            ->latest('issued_at')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(Receipt $receipt): array
    {
        $transaction = $receipt->transaction;

        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'title' => $transaction?->metadata['label'] ?? 'Paiement',
            'transaction_id' => $transaction?->id,
            'transaction_reference' => $transaction?->reference,
            'method' => $transaction?->operator?->name,
            'amount' => $receipt->amount,
            'amount_label' => $this->payments->money($receipt->amount),
            'issued_at' => $receipt->issued_at?->toIso8601String(),
            'date_label' => $receipt->issued_at ? CarbonImmutable::parse($receipt->issued_at)->locale('fr')->translatedFormat('j M Y') : null,
            'url' => $receipt->mediaFile ? $this->media->temporaryUrl($receipt->mediaFile) : null,
        ];
    }
}
