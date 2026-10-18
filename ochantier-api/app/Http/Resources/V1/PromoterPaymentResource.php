<?php

namespace App\Http\Resources\V1;

/**
 * Opération financière vue par le promoteur : prestation, commission Y, frais et total à payer.
 */
class PromoterPaymentResource extends PaymentResource
{
    protected string $audience = 'promoter';
}
