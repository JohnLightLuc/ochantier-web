<?php

namespace App\Console\Commands;

use App\Enums\QuoteVersionStatus;
use App\Models\PaymentOperator;
use App\Models\QuoteBid;
use App\Support\PhoneNumber;
use App\Support\Promoter\PromoterPayments;
use App\Support\Promoter\QuoteRequests;
use App\Support\Promoter\QuoteSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Simule localement les décisions du promoteur sur une proposition de devis d’entrepreneur : commande payée,
 * report de visite accepté ou refusé, accusé de réception du dernier devis envoyé. Refusée en production.
 */
class SimulateQuotePromoter extends Command
{
    protected $signature = 'ochantier:simulate-quote-promoter
        {proposal : Identifiant de la proposition de devis}
        {action : commander, accepter-report, refuser-report ou accuser-reception}';

    protected $description = 'Simule la commande, la décision sur un report de visite ou l’accusé de réception d’un devis par le promoteur (hors production)';

    public function handle(QuoteRequests $quotes, QuoteSimulator $simulator, PromoterPayments $payments): int
    {
        if (app()->isProduction()) {
            $this->error('Simulation interdite en production : le promoteur décide depuis son application.');

            return self::FAILURE;
        }

        $id = (string) $this->argument('proposal');
        $bid = Str::isUuid($id) ? QuoteBid::query()->with(['quoteRequestTrade.quoteRequest.site.promoterProfile.user', 'quoteRequestTrade.trade'])->find($id) : null;

        if (! $bid) {
            $this->error("Proposition {$id} introuvable.");

            return self::FAILURE;
        }

        try {
            $message = match ($this->argument('action')) {
                'commander' => $this->order($bid, $quotes, $simulator, $payments),
                'accepter-report' => $quotes->decideReschedule($bid, true) ? 'Report accepté.' : '',
                'refuser-report' => $quotes->decideReschedule($bid, false) ? 'Report refusé.' : '',
                'accuser-reception' => $this->acknowledge($bid, $quotes),
                default => throw ValidationException::withMessages(['action' => 'Action inconnue : commander, accepter-report, refuser-report ou accuser-reception.']),
            };
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($message);

        return self::SUCCESS;
    }

    /**
     * Commande des devis payée par le promoteur : cette proposition, et la meilleure de chaque autre métier
     * (proposée au besoin par le simulateur).
     */
    private function order(QuoteBid $bid, QuoteRequests $quotes, QuoteSimulator $simulator, PromoterPayments $payments): string
    {
        $request = $bid->quoteRequestTrade->quoteRequest;
        $simulator->bid($request);
        $ids = $request->trades()->get()->map(fn ($line) => $line->is($bid->quoteRequestTrade) ? $bid->id : $quotes->bestBids($line)->first()?->id)->filter()->values()->all();

        $user = $request->site->promoterProfile->user;
        $payable = $quotes->payable($user, $ids);
        $operator = PaymentOperator::query()->where('is_active', true)->where('supports_collection', true)->orderBy('created_at')->firstOrFail();
        $phone = PhoneNumber::of($user) ?? PhoneNumber::from('225', '0700000000');
        $transaction = $payments->pay($user, $request->site->promoterProfile, $payable, $operator, $phone, 'simulation-devis-'.Str::uuid());

        return 'Commande des devis '.$request->reference.' : paiement '.$transaction->reference.' '.$transaction->status->label().'.';
    }

    private function acknowledge(QuoteBid $bid, QuoteRequests $quotes): string
    {
        $version = $bid->versions()->where('status', QuoteVersionStatus::Envoye->value)->orderByDesc('version')->first();

        if (! $version) {
            throw ValidationException::withMessages(['quote' => 'Aucun devis envoyé en attente d’accusé de réception.']);
        }

        $quotes->acknowledge($version);

        return 'Réception du devis '.$version->number.' v'.$version->version.' confirmée.';
    }
}
