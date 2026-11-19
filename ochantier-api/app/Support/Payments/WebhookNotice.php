<?php

namespace App\Support\Payments;

/**
 * Notification authentifiée du prestataire sur un paiement : notre référence, l’issue annoncée et, s’il les
 * transmet, le montant et la devise réellement encaissés.
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
    ) {}
}
