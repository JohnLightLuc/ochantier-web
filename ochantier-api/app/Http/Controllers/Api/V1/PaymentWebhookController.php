<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentTransactionDirection;
use App\Enums\TransactionStatusSource;
use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\PromoterProfile;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\WebhookNotice;
use App\Support\Promoter\PromoterPayments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications du prestataire de paiement. La signature est vérifiée sur le contenu brut avant toute écriture ;
 * une notification déjà traitée est acquittée sans effet ; le montant annoncé doit être celui demandé. Réponses :
 * 200 une fois la notification enregistrée et appliquée, 400 si elle n’est pas authentique (à ne pas renvoyer),
 * 500 si son traitement a échoué (à renvoyer).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentGateway $gateway, PromoterPayments $payments): JsonResponse
    {
        abort_unless($provider === $gateway->name(), 404);

        $rawBody = (string) $request->getContent();
        $headers = collect($request->headers->all())->map(fn (array $values) => (string) ($values[0] ?? ''))->all();
        $notice = $gateway->verifyWebhook($rawBody, $headers);

        if ($notice === null) {
            Log::warning('Webhook de paiement refusé : signature ou contenu invalide', ['prestataire' => $provider, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Notification refusée.'], 400);
        }

        $event = $this->record($provider, $notice, $rawBody);

        if (in_array($event->processing_status, [WebhookProcessingStatus::Traite, WebhookProcessingStatus::Ignore], true)) {
            return response()->json(['message' => 'Notification déjà reçue.']);
        }

        $transaction = PaymentTransaction::query()
            ->where('reference', $notice->reference)
            ->where('direction', PaymentTransactionDirection::Encaissement)
            ->where('payer_type', (new PromoterProfile)->getMorphClass())
            ->first();

        if ($transaction === null) {
            return $this->ignore($event, 'Paiement inconnu.');
        }

        $expected = $transaction->amount + $transaction->fees_amount;

        $unexpected = $notice->amount !== null && ($notice->amount < $expected || $notice->amount > (int) floor($expected * (1 + $notice->amountTolerance)));

        if ($unexpected || ($notice->currency !== null && strtoupper($notice->currency) !== $transaction->currency)) {
            Log::critical('Webhook de paiement ignoré : montant ou devise différents de la demande', ['reference' => $transaction->reference, 'attendu' => $expected, 'recu' => $notice->amount, 'devise' => $notice->currency]);

            return $this->ignore($event, 'Montant ou devise différents de la demande.', $transaction);
        }

        try {
            $payments->resolve($transaction, $notice->result, TransactionStatusSource::Webhook, webhookEventId: $event->getKey());
        } catch (Throwable $exception) {
            $event->forceFill(['processing_status' => WebhookProcessingStatus::Erreur, 'attempts' => $event->attempts + 1, 'error' => mb_substr($exception->getMessage(), 0, 500), 'transaction_id' => $transaction->getKey()])->save();

            throw $exception;
        }

        $event->forceFill(['processing_status' => WebhookProcessingStatus::Traite, 'processed_at' => now(), 'attempts' => $event->attempts + 1, 'error' => null, 'transaction_id' => $transaction->getKey()])->save();

        return response()->json(['message' => 'Notification traitée.']);
    }

    /**
     * Enregistre la notification, ou retrouve celle déjà reçue (même identifiant d’événement ou même contenu).
     */
    private function record(string $provider, WebhookNotice $notice, string $rawBody): PaymentWebhookEvent
    {
        $hash = hash('sha256', $rawBody);
        $eventId = $notice->eventId ?? $hash;
        $find = fn (): ?PaymentWebhookEvent => PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where(fn ($query) => $query->where('provider_event_id', $eventId)->orWhere('payload_hash', $hash))
            ->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): PaymentWebhookEvent => PaymentWebhookEvent::query()->create([
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'event_type' => $notice->eventType,
                'signature_valid' => true,
                'payload' => json_decode($rawBody, true) ?? [],
                'payload_hash' => $hash,
                'processing_status' => WebhookProcessingStatus::Recu,
            ])->refresh());
        } catch (UniqueConstraintViolationException $exception) {
            // La même notification vient d’être enregistrée par une requête simultanée.
            return $find() ?? throw $exception;
        }
    }

    private function ignore(PaymentWebhookEvent $event, string $reason, ?PaymentTransaction $transaction = null): JsonResponse
    {
        $event->forceFill(['processing_status' => WebhookProcessingStatus::Ignore, 'processed_at' => now(), 'error' => $reason, 'transaction_id' => $transaction?->getKey()])->save();

        return response()->json(['message' => 'Notification ignorée.']);
    }
}
