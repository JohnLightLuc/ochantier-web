<?php

namespace App\Support\Payments;

use App\Models\PaymentOperator;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pilote de développement : le paiement réussit immédiatement, sauf pour un numéro se terminant par 0000 (solde
 * insuffisant), afin de tester les échecs. Jamais utilisé en production.
 */
class SimulatedPaymentGateway implements PaymentGateway
{
    public function collect(PaymentOperator $operator, PhoneNumber $phone, int $amount, string $reference): GatewayResult
    {
        $providerReference = 'SIM-'.Str::upper(Str::random(12));
        Log::info('Paiement simulé', ['operateur' => $operator->code, 'numero' => $phone->masked(), 'montant' => $amount, 'reference' => $reference]);

        return str_ends_with($phone->number, '0000')
            ? GatewayResult::failed('solde_insuffisant', 'Solde insuffisant sur ce compte '.$operator->name.'.', $providerReference)
            : GatewayResult::succeeded($providerReference);
    }
}
