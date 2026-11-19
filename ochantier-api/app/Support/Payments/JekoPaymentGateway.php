<?php

namespace App\Support\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encaissement Mobile Money par Jèko (API partenaire, mode direct) : Orange Money, MTN MoMo, Moov Money et Wave.
 *
 * La demande naît toujours « en attente » : le payeur valide sur son téléphone (MTN, Moov) ou sur la page de son
 * opérateur (Orange, Wave). Seuls le webhook signé et la lecture de la demande font foi, jamais le retour du
 * navigateur. Jèko n’envoie pas de webhook pour un encaissement échoué : c’est la vérification planifiée qui le
 * constate. Une référence ne sert qu’à une tentative : un nouvel essai est un nouveau paiement.
 *
 * Les montants Jèko sont en centimes (1 FCFA = 100) ; les nôtres en FCFA. Le décaissement n’est pas branché.
 */
class JekoPaymentGateway implements PaymentGateway
{
    /**
     * Opérateur (payment_operators.code) => moyen de paiement Jèko.
     *
     * @var array<string, string>
     */
    private const METHODS = ['orange_money' => 'orange', 'mtn_momo' => 'mtn', 'moov_money' => 'moov', 'wave' => 'wave'];

    /**
     * Moyens de paiement validés sur la page de l’opérateur ; les autres le sont par une demande sur le téléphone.
     *
     * @var list<string>
     */
    private const REDIRECTED = ['orange', 'wave'];

    /**
     * Durée pendant laquelle une demande Jèko accepte un paiement (minutes).
     */
    private const VALIDITY_MINUTES = 30;

    /**
     * Part que Jèko peut ajouter au montant débité au payeur (frais à sa charge). Un montant reçu inférieur au
     * montant demandé n’est jamais accepté.
     */
    private const FEE_TOLERANCE = 0.05;

    public function __construct(
        private string $apiKey,
        private string $apiKeyId,
        private string $storeId,
        private string $webhookSecret,
        private string $baseUrl,
        private string $returnUrl,
    ) {}

    /**
     * Passerelle construite depuis services.payments.jeko ; refuse de démarrer si un identifiant manque.
     */
    public static function fromConfig(): self
    {
        $config = (array) config('services.payments.jeko');
        $missing = collect(['api_key' => 'JEKO_API_KEY', 'api_key_id' => 'JEKO_API_KEY_ID', 'store_id' => 'JEKO_STORE_ID', 'webhook_secret' => 'JEKO_WEBHOOK_SECRET'])
            ->filter(fn (string $variable, string $key) => blank($config[$key] ?? null));

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Prestataire de paiement Jèko incomplet : '.$missing->implode(', ').' manquant.');
        }

        return new self(
            (string) $config['api_key'],
            (string) $config['api_key_id'],
            (string) $config['store_id'],
            (string) $config['webhook_secret'],
            rtrim((string) ($config['base_url'] ?? 'https://api.jeko.africa'), '/'),
            (string) (filled($config['return_url'] ?? null) ? $config['return_url'] : config('app.url')),
        );
    }

    public function name(): string
    {
        return 'jeko';
    }

    public function collect(PaymentOperator $operator, PhoneNumber $phone, int $amount, string $reference): GatewayResult
    {
        $method = self::METHODS[$operator->code] ?? null;

        if ($method === null) {
            return GatewayResult::failed('operateur_non_pris_en_charge', $operator->name.' n’est pas encore disponible pour le paiement.');
        }

        $separator = str_contains($this->returnUrl, '?') ? '&' : '?';
        $response = $this->http()->post('/partner_api/payment_requests', [
            'storeId' => $this->storeId,
            'amountCents' => $amount * 100,
            'currency' => 'XOF',
            'reference' => $reference,
            'paymentDetails' => [
                'type' => 'redirect',
                'data' => [
                    'paymentMethod' => $method,
                    'forceProviderDirect' => true,
                    'payerPhone' => $phone->e164(),
                    'successUrl' => $this->returnUrl.$separator.'reference='.urlencode($reference).'&status=success',
                    'errorUrl' => $this->returnUrl.$separator.'reference='.urlencode($reference).'&status=error',
                ],
            ],
        ]);

        if ($response->successful()) {
            $id = $response->json('id');

            if (! is_string($id) || $id === '') {
                throw new RuntimeException('Jèko : réponse sans identifiant à la demande de paiement '.$reference.'.');
            }

            return $this->result((string) $response->json('status'), $id, $response->json('errorReason'), $this->nextAction($method, $response->json('redirectUrl')));
        }

        // Refus définitif de cette tentative : requête invalide, plafond atteint ou opérateur qui refuse le numéro.
        if (in_array($response->status(), [400, 422], true)) {
            $code = (string) ($response->json('id') ?: 'demande_refusee');
            Log::warning('Jèko a refusé une demande de paiement', ['reference' => $reference, 'statut' => $response->status(), 'code' => $code]);

            return GatewayResult::failed(Str::limit($code, 60, ''), $code === 'third_party_payment_provider_error'
                ? $operator->name.' n’a pas pu lancer le paiement. Vérifiez le numéro puis réessayez.'
                : 'Le paiement n’a pas pu être lancé. Réessayez dans un instant.');
        }

        // Identifiants refusés, référence déjà connue, panne : on ne sait pas si une demande existe. Issue inconnue.
        throw new RuntimeException('Jèko : réponse '.$response->status().' à la demande de paiement '.$reference.'.');
    }

    public function status(PaymentTransaction $transaction): GatewayResult
    {
        // Demande dont la réponse n’est jamais arrivée : sans identifiant Jèko, seul le webhook peut la conclure.
        if (blank($transaction->provider_reference)) {
            return GatewayResult::pending(null);
        }

        $response = $this->http()->get('/partner_api/payment_requests/'.urlencode((string) $transaction->provider_reference));

        // Un 404 ne prouve rien (l’identifiant vient de Jèko : clé ou magasin changés ?) : issue inconnue, comme une panne.
        if (! $response->successful()) {
            throw new RuntimeException('Jèko : réponse '.$response->status().' à la lecture du paiement '.$transaction->reference.'.');
        }

        $status = (string) $response->json('status');
        $received = $response->json('transaction.amount.amount');
        $expected = $transaction->amount + $transaction->fees_amount;

        // Un succès n’est retenu que si le montant encaissé est lisible et conforme ; sinon le paiement reste ouvert.
        if ($status === 'success' && (! is_numeric($received) || ! $this->accepts($expected, intdiv((int) $received, 100)))) {
            Log::critical('Jèko annonce un encaissement dont le montant est absent ou différent de celui demandé', ['reference' => $transaction->reference, 'attendu' => $expected, 'recu' => is_numeric($received) ? intdiv((int) $received, 100) : null]);

            return GatewayResult::pending($transaction->provider_reference);
        }

        // L’échéance fixée à la demande n’est pas repoussée par une simple lecture.
        return $this->result($status, (string) $transaction->provider_reference, $response->json('errorReason'), null, false);
    }

    public function verifyWebhook(string $rawBody, array $headers): ?WebhookNotice
    {
        $signature = strtolower(trim((string) ($headers['jeko-signature'] ?? '')));

        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature)) {
            return null;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return null;
        }

        $event = (string) ($headers['jeko-event'] ?? 'TRANSACTION_COMPLETED');
        $id = is_string($payload['id'] ?? null) ? $payload['id'] : null;
        $status = (string) ($payload['status'] ?? '');
        $reference = $payload['transactionDetails']['reference'] ?? null;
        $requestId = $payload['transactionDetails']['id'] ?? null;

        // Authentique mais sans objet ici (reversement, autre événement) : acquittée sans effet, faute de paiement.
        if (($payload['transactionType'] ?? null) !== 'payment' || ! is_string($reference) || $reference === '') {
            return new WebhookNotice('', GatewayResult::pending(null), $id ? $id.':'.$event : null, Str::limit($event, 60, ''));
        }

        $amount = $payload['amount']['amount'] ?? null;

        return new WebhookNotice(
            reference: $reference,
            result: $this->result($status, is_string($requestId) ? $requestId : null, null, null, false),
            eventId: $id ? $id.':'.$status : null,
            eventType: Str::limit($event, 60, ''),
            amount: is_numeric($amount) ? intdiv((int) $amount, 100) : null,
            currency: is_string($payload['amount']['currency'] ?? null) ? $payload['amount']['currency'] : null,
            amountTolerance: self::FEE_TOLERANCE,
        );
    }

    public function disburse(PaymentMethod $method, int $amount, string $reference): GatewayResult
    {
        return GatewayResult::failed('decaissement_non_branche', 'Le décaissement par Jèko n’est pas encore activé.');
    }

    /**
     * Montant reçu acceptable : celui demandé, ou davantage dans la limite des frais que Jèko ajoute au payeur.
     */
    private function accepts(int $expected, int $received): bool
    {
        return $received >= $expected && $received <= (int) floor($expected * (1 + self::FEE_TOLERANCE));
    }

    /**
     * @param  array{type: string, url?: string}|null  $nextAction
     */
    private function result(string $status, ?string $requestId, mixed $errorReason, ?array $nextAction = null, bool $starts = true): GatewayResult
    {
        return match ($status) {
            'success' => new GatewayResult('reussie', $requestId),
            'error' => GatewayResult::failed('refuse', is_string($errorReason) && str_contains(strtolower($errorReason), 'expired')
                ? 'La demande de paiement a expiré sans validation.'
                : 'Le paiement a été refusé ou annulé.', $requestId),
            default => GatewayResult::pending($requestId, $nextAction, $starts ? now()->addMinutes(self::VALIDITY_MINUTES) : null),
        };
    }

    /**
     * Ce que le payeur doit faire : ouvrir la page de son opérateur, ou valider la demande reçue sur son téléphone.
     *
     * @return array{type: string, url?: string}
     */
    private function nextAction(string $method, mixed $redirectUrl): array
    {
        return in_array($method, self::REDIRECTED, true) && is_string($redirectUrl) && $redirectUrl !== ''
            ? ['type' => 'open_url', 'url' => $redirectUrl]
            : ['type' => 'wait_push'];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-API-KEY' => $this->apiKey, 'X-API-KEY-ID' => $this->apiKeyId])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }
}
