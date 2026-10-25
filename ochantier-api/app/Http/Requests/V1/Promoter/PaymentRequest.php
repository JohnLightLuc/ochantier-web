<?php

namespace App\Http\Requests\V1\Promoter;

use App\Http\Requests\V1\PhoneFields;
use App\Models\PaymentOperator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Paiement Mobile Money (« Payer ») ou son devis : ce qui est réglé (acompte d’une proposition, échéances, versement
 * d’un paiement flexible, formule d’abonnement ou facture de renouvellement), l’opérateur et, pour payer, le numéro
 * qui reçoit la demande de paiement. installments (2 ou 3) règle un acompte ou des échéances en plusieurs fois.
 */
class PaymentRequest extends FormRequest
{
    use PhoneFields;

    private ?PaymentOperator $operatorModel = null;

    /**
     * Ce que l’on peut payer : une seule cible par paiement.
     */
    private const TARGETS = ['proposal_id', 'schedule_ids', 'installment_id', 'plan_code', 'subscription_invoice_id'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paying = $this->routeIs('*.payments.store');

        return [
            'proposal_id' => ['nullable', 'uuid'],
            'schedule_ids' => ['nullable', 'array', 'min:1', 'max:20'],
            'schedule_ids.*' => ['uuid', 'distinct'],
            'installment_id' => ['nullable', 'uuid'],
            'plan_code' => ['nullable', 'string', 'max:30'],
            'subscription_invoice_id' => ['nullable', 'uuid'],
            'installments' => ['sometimes', 'integer', 'in:1,2,3'],
            'operator_code' => ['required', 'string', Rule::exists('payment_operators', 'code')->where('is_active', true)->where('supports_collection', true)],
            ...($paying ? $this->phoneRules() : []),
            'remember_number' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $targets = collect(self::TARGETS)->filter(fn (string $key) => $this->filled($key));

                if ($targets->count() !== 1) {
                    $validator->errors()->add($targets->first() ?? 'proposal_id', $targets->isEmpty()
                        ? 'Indiquez ce que vous payez : proposal_id (acompte), schedule_ids (échéances), installment_id (versement), plan_code (formule) ou subscription_invoice_id (facture d’abonnement).'
                        : 'Payez une seule chose à la fois.');
                }

                if ($this->installments() > 1 && ! $targets->intersect(['proposal_id', 'schedule_ids'])->count()) {
                    $validator->errors()->add('installments', 'Seuls un acompte ou des échéances peuvent être réglés en plusieurs fois.');
                }

                $operator = $this->operator();
                $phone = $this->phoneNumber();

                if ($operator?->number_pattern && $phone?->dialCode === '225' && ! $validator->errors()->has('phone_number') && ! preg_match('/'.$operator->number_pattern.'/', $phone->number)) {
                    $validator->errors()->add('phone_number', 'Ce numéro n’est pas un numéro '.$operator->name.'.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'installments.in' => 'Payez en 1, 2 ou 3 fois.',
            'installments.integer' => 'Payez en 1, 2 ou 3 fois.',
            'operator_code.required' => 'Choisissez un moyen de paiement.',
            'operator_code.exists' => 'Moyen de paiement indisponible.',
        ];
    }

    public function operator(): ?PaymentOperator
    {
        return $this->operatorModel ??= PaymentOperator::query()->where('code', (string) $this->input('operator_code'))->first();
    }

    /**
     * @return array{proposal_id?: string, schedule_ids?: list<string>, installment_id?: string, plan_code?: string, subscription_invoice_id?: string}
     */
    public function target(): array
    {
        if ($this->filled('schedule_ids')) {
            return ['schedule_ids' => array_values((array) $this->input('schedule_ids'))];
        }

        $key = collect(self::TARGETS)->first(fn (string $key) => $this->filled($key));

        return [$key => (string) $this->input($key)];
    }

    /**
     * Nombre de versements demandé (paiement flexible), 1 par défaut.
     */
    public function installments(): int
    {
        return (int) ($this->input('installments') ?: 1);
    }
}
