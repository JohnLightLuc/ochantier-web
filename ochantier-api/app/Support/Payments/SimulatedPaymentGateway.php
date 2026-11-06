<?php

namespace App\Support\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentOperator;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pilote de développement : l’opération réussit immédiatement, sauf pour un numéro se terminant par 0000 (solde
 * insuffisant à l’encaissement, compte introuvable au décaissement), afin de tester les échecs. Jamais utilisé en
 * production.
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

    public function disburse(PaymentMethod $method, int $amount, string $reference): GatewayResult
    {
        $providerReference = 'SIM-'.Str::upper(Str::random(12));
        Log::info('Décaissement simulé', ['operateur' => $method->operator?->code, 'numero' => $method->account_number_masked, 'montant' => $amount, 'reference' => $reference]);

        return str_ends_with($method->account_number_encrypted, '0000')
            ? GatewayResult::failed('compte_introuvable', 'Ce numéro '.$method->operator?->name.' ne peut pas recevoir ce paiement.', $providerReference)
            : GatewayResult::succeeded($providerReference);
    }
}
