<?php

namespace App\Support\Payments;

use App\Models\PaymentOperator;
use App\Support\PhoneNumber;

/**
 * Encaissement Mobile Money (Orange Money, MTN MoMo, Moov Money, Wave). Brancher ici le prestataire retenu.
 */
interface PaymentGateway
{
    /**
     * Demande le paiement au numéro donné. Le montant inclut les frais de l’opérateur.
     */
    public function collect(PaymentOperator $operator, PhoneNumber $phone, int $amount, string $reference): GatewayResult;
}
