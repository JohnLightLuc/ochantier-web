<?php

namespace App\Support\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pilote de développement : l’opération réussit immédiatement, sauf pour un numéro se terminant par 0000 (solde
 * insuffisant à l’encaissement, compte introuvable au décaissement), afin de tester les échecs. Jamais utilisé en
 * production.
 *
 * Encaissement, pour tester les issues différées : 1111 reste en attente puis réussit à la vérification suivante,
 * 2222 reste en attente jusqu’à expiration, 3333 simule un prestataire injoignable (issue inconnue). Un webhook
 * simulé est signé en HMAC-SHA256 avec services.payments.simulation_webhook_secret (en-tête X-Simulation-Signature).
 */
class SimulatedPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'simulation';
    }

    public function collect(PaymentOperator $operator, PhoneNumber $phone, int $amount, string $reference): GatewayResult
    {
        Log::info('Paiement simulé', ['operateur' => $operator->code, 'numero' => $phone->masked(), 'montant' => $amount, 'reference' => $reference]);

        return match (true) {
            str_ends_with($phone->number, '0000') => GatewayResult::failed('solde_insuffisant', 'Solde insuffisant sur ce compte '.$operator->name.'.', $this->reference('SIM')),
            str_ends_with($phone->number, '1111') => GatewayResult::pending($this->reference('SIM-PEND'), ['type' => 'wait_push'], now()->addMinutes(10)),
            str_ends_with($phone->number, '2222') => GatewayResult::pending($this->reference('SIM-STAY'), ['type' => 'wait_push'], now()->addMinutes(10)),
            str_ends_with($phone->number, '3333') => throw new RuntimeException('Prestataire de paiement simulé injoignable.'),
            default => GatewayResult::succeeded($this->reference('SIM')),
        };
    }

    public function status(PaymentTransaction $transaction): GatewayResult
    {
        $reference = $transaction->provider_reference;

        return match (true) {
            $reference === null => GatewayResult::failed('introuvable', 'Le paiement n’a pas abouti.'),
            str_starts_with($reference, 'SIM-STAY') => GatewayResult::pending($reference),
            default => GatewayResult::succeeded($reference),
        };
    }

    public function verifyWebhook(string $rawBody, array $headers): ?WebhookNotice
    {
        $secret = (string) config('services.payments.simulation_webhook_secret');
        $signature = (string) ($headers['x-simulation-signature'] ?? '');

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || ! is_string($payload['reference'] ?? null) || ! in_array($payload['status'] ?? null, ['reussie', 'echouee'], true)) {
            return null;
        }

        $providerReference = is_string($payload['provider_reference'] ?? null) ? $payload['provider_reference'] : null;

        return new WebhookNotice(
            reference: $payload['reference'],
            result: $payload['status'] === 'reussie'
                ? GatewayResult::succeeded($providerReference ?? $this->reference('SIM'))
                : GatewayResult::failed((string) ($payload['failure_code'] ?? 'refuse'), (string) ($payload['failure_message'] ?? 'Le paiement a été refusé.'), $providerReference),
            eventId: is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null,
            eventType: 'paiement.'.$payload['status'],
            amount: isset($payload['amount']) ? (int) $payload['amount'] : null,
            currency: is_string($payload['currency'] ?? null) ? $payload['currency'] : null,
        );
    }

    public function disburse(PaymentMethod $method, int $amount, string $reference): GatewayResult
    {
        $providerReference = $this->reference('SIM');
        Log::info('Décaissement simulé', ['operateur' => $method->operator?->code, 'numero' => $method->account_number_masked, 'montant' => $amount, 'reference' => $reference]);

        return str_ends_with($method->account_number_encrypted, '0000')
            ? GatewayResult::failed('compte_introuvable', 'Ce numéro '.$method->operator?->name.' ne peut pas recevoir ce paiement.', $providerReference)
            : GatewayResult::succeeded($providerReference);
    }

    private function reference(string $prefix): string
    {
        return $prefix.'-'.Str::upper(Str::random(12));
    }
}
