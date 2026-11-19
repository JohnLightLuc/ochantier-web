<?php

namespace App\Support\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Models\PaymentTransaction;
use App\Support\PhoneNumber;

/**
 * Encaissement et décaissement Mobile Money (Orange Money, MTN MoMo, Moov Money, Wave). Brancher ici le prestataire
 * retenu.
 *
 * Une exception levée par collect() ou status() signifie « issue inconnue » (prestataire injoignable, délai
 * dépassé), jamais un échec : le paiement reste ouvert et sera revérifié.
 */
interface PaymentGateway
{
    /**
     * Nom du prestataire, tel qu’il figure dans l’adresse de son webhook.
     */
    public function name(): string;

    /**
     * Demande le paiement au numéro donné. Le montant inclut les frais de l’opérateur.
     */
    public function collect(PaymentOperator $operator, PhoneNumber $phone, int $amount, string $reference): GatewayResult;

    /**
     * État actuel d’une demande de paiement chez le prestataire.
     */
    public function status(PaymentTransaction $transaction): GatewayResult;

    /**
     * Authentifie une notification reçue (signature calculée sur le contenu brut) ; null si elle n’est pas valide.
     *
     * @param  array<string, string>  $headers  noms en minuscules
     */
    public function verifyWebhook(string $rawBody, array $headers): ?WebhookNotice;

    /**
     * Envoie le montant sur le moyen de paiement du bénéficiaire.
     */
    public function disburse(PaymentMethod $method, int $amount, string $reference): GatewayResult;
}
