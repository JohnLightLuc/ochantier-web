<?php

namespace App\Support\Payments;

use Carbon\CarbonInterface;

/**
 * Réponse du prestataire à une demande de paiement : réussie, en attente de validation sur le téléphone, ou
 * échouée avec un code et un message à afficher. En attente, $nextAction dit ce que le payeur doit faire
 * (type : wait_push, open_url, ussd ; url ou code selon le type) et $expiresAt jusqu’à quand.
 */
final class GatewayResult
{
    /**
     * @param  array{type: string, url?: string, code?: string}|null  $nextAction
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly ?array $nextAction = null,
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}

    public static function succeeded(string $providerReference): self
    {
        return new self('reussie', $providerReference);
    }

    /**
     * @param  array{type: string, url?: string, code?: string}|null  $nextAction
     */
    public static function pending(?string $providerReference, ?array $nextAction = null, ?CarbonInterface $expiresAt = null): self
    {
        return new self('en_attente', $providerReference, null, null, $nextAction, $expiresAt);
    }

    public static function failed(string $code, string $message, ?string $providerReference = null): self
    {
        return new self('echouee', $providerReference, $code, $message);
    }
}
