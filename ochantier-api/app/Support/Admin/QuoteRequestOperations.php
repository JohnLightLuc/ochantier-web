<?php

namespace App\Support\Admin;

use App\Enums\QuoteRequestStatus;
use App\Models\Admin;
use App\Models\Notification;
use App\Models\QuoteRequestTrade;
use App\Support\Contractor\ContractorNotifier;
use App\Support\Promoter\PromoterNotifier;
use App\Support\Promoter\QuoteRequests;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Décisions du Back Office sur une demande de devis : prolonger la date limite, annuler la demande (avant la
 * commande, aucun paiement) et relancer le promoteur ou le professionnel choisi. Chaque décision est tracée dans le
 * journal d’audit de la demande.
 */
class QuoteRequestOperations
{
    /**
     * Statuts affichés pour lesquels une relance a un sens, par destinataire.
     */
    public const REMINDABLE = [
        'promoter' => ['ouverte', 'report_propose', 'devis_envoye'],
        'contractor' => ['visite_programmee', 'devis_en_preparation'],
    ];

    public function __construct(private QuoteRequestDirectory $directory, private QuoteRequests $quotes, private PromoterNotifier $promoters, private ContractorNotifier $contractors) {}

    /**
     * Repousse la date limite de réponse d’une demande encore en recherche, même si elle a expiré : les
     * professionnels peuvent de nouveau proposer une visite.
     */
    public function extend(QuoteRequestTrade $line, CarbonInterface $deadline, Admin $admin, Request $request): void
    {
        $quoteRequest = $line->quoteRequest;

        if ($quoteRequest->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_request' => 'Seule une demande encore en recherche peut être prolongée.']);
        }

        $before = $quoteRequest->response_deadline?->toIso8601String();
        $quoteRequest->update(['response_deadline' => $deadline]);

        AdminAudit::record($admin, 'offers', 'quote_request.extended', $request, $quoteRequest, ['deadline' => $before], ['deadline' => $deadline->toIso8601String()]);
    }

    /**
     * Annule toute la demande (tous ses corps de métier) avant la commande : le chantier revient au brouillon, les
     * propositions ne sont pas retenues, le promoteur et les professionnels sont prévenus.
     */
    public function cancel(QuoteRequestTrade $line, Admin $admin, string $reason, Request $request): void
    {
        $quoteRequest = $line->quoteRequest;

        if ($quoteRequest->status !== QuoteRequestStatus::Recherche) {
            throw ValidationException::withMessages(['quote_request' => 'Une demande déjà commandée (visite payée) ne peut plus être annulée.']);
        }

        $site = $quoteRequest->site;
        $this->quotes->cancel($site, $admin, $reason);

        AdminAudit::record($admin, 'offers', 'quote_request.cancelled', $request, $quoteRequest, ['status' => 'recherche'], ['status' => 'annule', 'reason' => $reason]);
        $this->promoters->notify($site->promoterProfile, 'quote.cancelled', 'Demande de devis annulée · '.$site->label, $reason, $site, ['type' => 'quote_request', 'id' => $site->id], $quoteRequest);
    }

    /**
     * Rappel au promoteur (choisir une proposition, répondre au report, accuser réception du devis) ou au
     * professionnel choisi (visite à faire, devis à envoyer). Un seul rappel par destinataire et par jour.
     */
    public function remind(QuoteRequestTrade $line, string $target, ?string $message, Admin $admin, Request $request): void
    {
        $status = $this->directory->status($line);

        if (! in_array($status, self::REMINDABLE[$target], true)) {
            throw ValidationException::withMessages(['target' => $target === 'promoter' ? 'Aucune action n’est attendue du promoteur à ce stade.' : 'Aucune action n’est attendue du professionnel à ce stade.']);
        }

        $quoteRequest = $line->quoteRequest;
        $site = $quoteRequest->site;
        $trade = mb_strtolower((string) $line->trade?->label);
        $body = $message ?: match ($status) {
            'ouverte' => 'Des professionnels ont proposé une visite pour « '.$trade.' » : choisissez la vôtre avant la date limite.',
            'report_propose' => 'Le professionnel propose un report de la visite « '.$trade.' » : acceptez-le ou refusez-le.',
            'devis_envoye' => 'Le devis « '.$trade.' » vous a été envoyé : consultez-le et confirmez sa réception.',
            'visite_programmee' => 'Rappel : la visite du chantier « '.$site->label.' » est prévue le '.$line->selectedBid?->visit_date?->format('d/m/Y').'.',
            default => 'La visite du chantier « '.$site->label.' » est faite : pensez à envoyer votre devis au promoteur.',
        };
        $dedup = 'quote.reminder:'.$line->getKey().':'.$target.':'.now()->toDateString();

        $sent = match ($target) {
            'promoter' => $this->promoters->notify($site->promoterProfile, 'quote.reminder', 'Rappel · '.$site->label, $body, $site, ['type' => 'quote_request', 'id' => $site->id], $quoteRequest, $dedup) > 0,
            default => $this->remindContractor($line, $body, $dedup),
        };

        if (! $sent) {
            throw ValidationException::withMessages(['target' => 'Une relance a déjà été envoyée aujourd’hui à ce destinataire.']);
        }

        AdminAudit::record($admin, 'offers', 'quote_request.reminded', $request, $quoteRequest, null, ['line_id' => $line->getKey(), 'target' => $target, 'message' => $body]);
    }

    private function remindContractor(QuoteRequestTrade $line, string $body, string $dedup): bool
    {
        $bid = $line->selectedBid;

        if (! $bid?->contractor || Notification::query()->where('dedup_key', 'like', $dedup.':%')->exists()) {
            return false;
        }

        $this->contractors->notify($bid->contractor, 'quote.reminder', 'Rappel · '.$line->quoteRequest->site->label, $body, ['type' => 'quote_proposal', 'id' => $bid->id], $bid, $dedup);

        return true;
    }
}
