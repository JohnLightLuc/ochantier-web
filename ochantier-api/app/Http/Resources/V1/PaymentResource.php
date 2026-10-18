<?php

namespace App\Http\Resources\V1;

use App\Support\PaymentLedger;
use Illuminate\Http\Request;

/**
 * Opération financière, vue par l’ouvrier (net à recevoir, reversement) ou par le promoteur (total à payer).
 * Les montants sont calculés par les mêmes règles que le Back-office (PaymentLedger).
 *
 * @mixin \App\Models\Payment
 */
class PaymentResource extends Resource
{
    /**
     * Destinataire de la vue : « worker » ou « promoter » (PromoterPaymentResource).
     */
    protected string $audience = 'worker';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = PaymentLedger::calculate($this->resource->loadMissing('items', 'refunds'));
        $common = [
            'reference' => $this->reference,
            'date' => $this->date($this->date),
            'type' => $this->type,
            'site' => $this->whenLoaded('site', fn (): array => ['slug' => $this->site->slug, 'name' => $this->site->name]),
            'assignment_reference' => $this->assignment_reference,
            'period_start' => $this->date($this->period_start),
            'period_end' => $this->date($this->period_end),
            'days' => $this->days,
            'daily_rate' => $this->daily_rate,
            'gross' => $c['gross'],
            'bonus' => $c['bonus'],
            'indemnity' => $c['indemnity'],
        ];

        if ($this->audience === 'promoter') {
            return [
                ...$common,
                'worker' => $this->whenLoaded('worker', fn (): ?array => $this->worker ? ['slug' => $this->worker->slug, 'name' => $this->worker->name] : null),
                'commission' => $c['comP'],
                'fees' => $c['fees'],
                'tax' => $c['tax'],
                'total' => $c['promoterTotal'],
                'refunded' => $c['refunded'],
                'method' => $this->method,
                'status' => $this->pay_status,
                'paid_at' => $this->date($this->paid_at),
                'documents' => $this->documents,
            ];
        }

        return [
            ...$common,
            'deduction' => $c['deduction'],
            'commission' => $c['comO'],
            'net' => $c['net'],
            'method' => $this->worker_method,
            'account' => $this->worker_account ? '•••• '.substr($this->worker_account, -4) : null,
            'status' => $this->payout_status,
            'paid_at' => $this->date($this->payout_at),
            'items' => $this->items->whereIn('status', ['Validé', 'Approuvée'])->map(fn ($i): array => ['kind' => $i->kind, 'type' => $i->type, 'amount' => $i->amount, 'motif' => $i->motif])->values(),
        ];
    }
}
