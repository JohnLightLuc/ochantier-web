<?php

namespace Tests\Unit;

use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Support\Payments\JekoPaymentGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Passerelle Jèko : demande de paiement, lecture de son état et authentification des webhooks, sans appel réel.
 */
class JekoPaymentGatewayTest extends TestCase
{
    private const REQUEST_ID = 'd22c81f3-ee04-4ec5-8bd2-cd8af5dabcfc';

    private function gateway(): JekoPaymentGateway
    {
        return new JekoPaymentGateway('cle', 'id-cle', '59ae202a-f583-4a15-970f-9e99bd1e0baa', 'secret-webhook', 'https://api.jeko.test', 'https://ochantier.test/paiement');
    }

    private function operator(string $code = 'orange_money', string $name = 'Orange Money'): PaymentOperator
    {
        return new PaymentOperator(['code' => $code, 'name' => $name]);
    }

    private function transaction(?string $providerReference = self::REQUEST_ID): PaymentTransaction
    {
        return (new PaymentTransaction)->forceFill(['reference' => 'PAY-2026-000001', 'provider_reference' => $providerReference, 'amount' => 10000, 'fees_amount' => 150]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, string>}
     */
    private function signed(array $payload, string $secret = 'secret-webhook'): array
    {
        $body = (string) json_encode($payload);

        return [$body, ['jeko-signature' => hash_hmac('sha256', $body, $secret), 'jeko-event' => 'TRANSACTION_COMPLETED']];
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(int $amountCents = 1015000, string $status = 'success'): array
    {
        return [
            'id' => 'txn_1',
            'amount' => ['amount' => $amountCents, 'currency' => 'XOF'],
            'status' => $status,
            'transactionType' => 'payment',
            'transactionDetails' => ['id' => self::REQUEST_ID, 'reference' => 'PAY-2026-000001'],
        ];
    }

    public function test_a_payment_request_is_sent_in_cents_and_starts_pending_on_the_operator_page(): void
    {
        Http::fake(['api.jeko.test/*' => Http::response(['id' => self::REQUEST_ID, 'status' => 'pending', 'redirectUrl' => 'https://webpay.orange.test/abc', 'errorReason' => null])]);

        $result = $this->gateway()->collect($this->operator(), new PhoneNumber('225', '0712345634'), 10150, 'PAY-2026-000001');

        $this->assertSame('en_attente', $result->status);
        $this->assertSame(self::REQUEST_ID, $result->providerReference);
        $this->assertSame(['type' => 'open_url', 'url' => 'https://webpay.orange.test/abc'], $result->nextAction);
        $this->assertNotNull($result->expiresAt);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.jeko.test/partner_api/payment_requests'
                && $request->hasHeader('X-API-KEY', 'cle')
                && $request->hasHeader('X-API-KEY-ID', 'id-cle')
                && $request['amountCents'] === 1015000
                && $request['currency'] === 'XOF'
                && $request['reference'] === 'PAY-2026-000001'
                && $request['paymentDetails']['type'] === 'redirect'
                && $request['paymentDetails']['data']['paymentMethod'] === 'orange'
                && $request['paymentDetails']['data']['forceProviderDirect'] === true
                && $request['paymentDetails']['data']['payerPhone'] === '+2250712345634'
                && str_starts_with($request['paymentDetails']['data']['successUrl'], 'https://ochantier.test/paiement?reference=PAY-2026-000001');
        });
    }

    public function test_mtn_and_moov_wait_for_a_validation_on_the_phone(): void
    {
        Http::fake(['api.jeko.test/*' => Http::response(['id' => self::REQUEST_ID, 'status' => 'pending', 'redirectUrl' => 'https://pay.jeko.africa/pay_request/pr/'.self::REQUEST_ID])]);

        $result = $this->gateway()->collect($this->operator('mtn_momo', 'MTN MoMo'), new PhoneNumber('225', '0512345634'), 5000, 'PAY-2026-000002');

        $this->assertSame('en_attente', $result->status);
        $this->assertSame(['type' => 'wait_push'], $result->nextAction);
    }

    public function test_a_refusal_fails_the_attempt_but_an_outage_leaves_the_outcome_unknown(): void
    {
        Http::fake(['api.jeko.test/*' => Http::sequence()
            ->push(['id' => 'third_party_payment_provider_error', 'message' => 'An error occurred'], 400)
            ->push(['id' => 'validation_error'], 422)
            ->push(['id' => 'payment_request_exists_with_reference'], 409)
            ->push(['message' => 'boom'], 500)]);
        $gateway = $this->gateway();
        $phone = new PhoneNumber('225', '0712345634');

        $refused = $gateway->collect($this->operator(), $phone, 5000, 'PAY-1');
        $this->assertSame('echouee', $refused->status);
        $this->assertSame('third_party_payment_provider_error', $refused->failureCode);
        $this->assertStringContainsString('Orange Money', (string) $refused->failureMessage);
        $this->assertSame('echouee', $gateway->collect($this->operator(), $phone, 5000, 'PAY-2')->status);

        foreach (['PAY-3', 'PAY-4'] as $reference) {
            try {
                $gateway->collect($this->operator(), $phone, 5000, $reference);
                $this->fail('Une réponse 409 ou 500 ne doit jamais valoir échec ni succès.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($reference, $exception->getMessage());
            }
        }
    }

    public function test_an_unreachable_provider_or_an_unknown_operator_is_handled(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $unknown = $this->gateway()->collect($this->operator('carte', 'Carte'), new PhoneNumber('225', '0712345634'), 5000, 'PAY-1');
        $this->assertSame('echouee', $unknown->status);
        Http::assertNothingSent();

        $this->expectException(ConnectionException::class);
        $this->gateway()->collect($this->operator(), new PhoneNumber('225', '0712345634'), 5000, 'PAY-2');
    }

    public function test_the_status_of_a_request_is_read_from_jeko(): void
    {
        Http::fake(['api.jeko.test/*' => Http::sequence()
            ->push(['id' => self::REQUEST_ID, 'status' => 'pending'])
            ->push(['id' => self::REQUEST_ID, 'status' => 'success', 'transaction' => ['amount' => ['amount' => 1015000, 'currency' => 'XOF']]])
            ->push(['id' => self::REQUEST_ID, 'status' => 'success', 'transaction' => ['amount' => ['amount' => 100000, 'currency' => 'XOF']]])
            ->push(['id' => self::REQUEST_ID, 'status' => 'error', 'errorReason' => 'Payment request has expired'])
            ->push(['id' => self::REQUEST_ID, 'status' => 'success'])
            ->push([], 404)
            ->push([], 503)]);
        $gateway = $this->gateway();

        $pending = $gateway->status($this->transaction());
        $this->assertSame('en_attente', $pending->status);
        // Une lecture ne repousse pas l’échéance fixée à la demande.
        $this->assertNull($pending->expiresAt);
        $this->assertSame('reussie', $gateway->status($this->transaction())->status);
        // Un encaissement d’un montant inférieur au montant demandé n’est jamais confirmé.
        $this->assertSame('en_attente', $gateway->status($this->transaction())->status);
        $expired = $gateway->status($this->transaction());
        $this->assertSame('echouee', $expired->status);
        $this->assertSame('La demande de paiement a expiré sans validation.', $expired->failureMessage);
        // Un succès sans montant lisible n’est pas confirmé non plus.
        $this->assertSame('en_attente', $gateway->status($this->transaction())->status);

        // Ni un 404 ni une panne ne valent échec : l’issue reste inconnue.
        foreach ([404, 503] as $status) {
            try {
                $gateway->status($this->transaction());
                $this->fail("Une réponse {$status} de Jèko laisse l’issue inconnue.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString((string) $status, $exception->getMessage());
            }
        }

        // Sans identifiant Jèko, rien à interroger : le paiement reste en attente du webhook.
        $this->assertSame('en_attente', $gateway->status($this->transaction(null))->status);
        Http::assertSentCount(7);
    }

    public function test_a_webhook_is_trusted_only_with_a_valid_signature_on_the_raw_body(): void
    {
        $gateway = $this->gateway();
        [$body, $headers] = $this->signed($this->completed());

        $notice = $gateway->verifyWebhook($body, $headers);
        $this->assertNotNull($notice);
        $this->assertSame('PAY-2026-000001', $notice->reference);
        $this->assertSame('reussie', $notice->result->status);
        $this->assertSame(self::REQUEST_ID, $notice->result->providerReference);
        $this->assertSame(10150, $notice->amount);
        $this->assertSame('XOF', $notice->currency);
        $this->assertSame('txn_1:success', $notice->eventId);
        $this->assertGreaterThan(0, $notice->amountTolerance);

        $this->assertNull($gateway->verifyWebhook($body.' ', $headers));
        $this->assertNull($gateway->verifyWebhook($body, ['jeko-signature' => '']));
        $this->assertNull($gateway->verifyWebhook(...$this->signed($this->completed(), 'autre-secret')));
        $this->assertNotNull($gateway->verifyWebhook($body, [...$headers, 'jeko-signature' => strtoupper($headers['jeko-signature'])]));
    }

    public function test_a_transfer_or_another_event_is_acknowledged_without_touching_a_payment(): void
    {
        $gateway = $this->gateway();

        $transfer = $gateway->verifyWebhook(...$this->signed([...$this->completed(), 'transactionType' => 'transfer']));
        $this->assertNotNull($transfer);
        $this->assertSame('', $transfer->reference);

        $failed = $gateway->verifyWebhook(...$this->signed($this->completed(status: 'error')));
        $this->assertSame('echouee', $failed?->result->status);
    }

    public function test_the_gateway_refuses_to_start_without_its_credentials(): void
    {
        config(['services.payments.jeko' => ['api_key' => 'cle', 'api_key_id' => '', 'store_id' => 'magasin', 'webhook_secret' => null]]);

        try {
            JekoPaymentGateway::fromConfig();
            $this->fail('Une configuration incomplète doit être refusée.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('JEKO_API_KEY_ID', $exception->getMessage());
            $this->assertStringContainsString('JEKO_WEBHOOK_SECRET', $exception->getMessage());
            $this->assertStringNotContainsString('cle', $exception->getMessage());
        }

        $this->assertSame('echouee', $this->gateway()->disburse(new PaymentMethod, 1000, 'DEC-1')->status);
    }
}
