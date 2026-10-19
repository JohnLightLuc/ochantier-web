<?php

namespace App\Support\Payments;

/**
 * Réponse du prestataire à une demande de paiement : réussie, en attente de validation sur le téléphone, ou
 * échouée avec un code et un message à afficher.
 */
final class GatewayResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
    ) {}

    public static function succeeded(string $providerReference): self
    {
        return new self('reussie', $providerReference);
    }

    public static function failed(string $code, string $message, ?string $providerReference = null): self
    {
        return new self('echouee', $providerReference, $code, $message);
    }
}
