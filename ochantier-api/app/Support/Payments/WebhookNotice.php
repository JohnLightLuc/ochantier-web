<?php

namespace App\Support\Payments;

/**
 * Notification authentifiée du prestataire sur un paiement : notre référence, l’issue annoncée et, s’il les
 * transmet, le montant et la devise réellement encaissés. $amountTolerance est la part que le prestataire peut
 * ajouter au montant demandé (frais à la charge du payeur) ; un montant inférieur n’est jamais accepté.
 */
final class WebhookNotice
{
    public function __construct(
        public readonly string $reference,
        public readonly GatewayResult $result,
        public readonly ?string $eventId = null,
        public readonly ?string $eventType = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly float $amountTolerance = 0.0,
    ) {}
}
