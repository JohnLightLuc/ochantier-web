<?php

namespace App\Support\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Support\PhoneNumber;

/**
 * Encaissement et décaissement Mobile Money (Orange Money, MTN MoMo, Moov Money, Wave). Brancher ici le prestataire
 * retenu.
 */
interface PaymentGateway
{
    /**
     * Demande le paiement au numéro donné. Le montant inclut les frais de l’opérateur.
     */
    public function collect(PaymentOperator $operator, PhoneNumber $phone, int $amount, string $reference): GatewayResult;

    /**
     * Envoie le montant sur le moyen de paiement du bénéficiaire.
     */
    public function disburse(PaymentMethod $method, int $amount, string $reference): GatewayResult;
}
