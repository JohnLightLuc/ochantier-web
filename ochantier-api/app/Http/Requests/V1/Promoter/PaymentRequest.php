<?php

namespace App\Http\Requests\V1\Promoter;

use App\Http\Requests\V1\PhoneFields;
use App\Models\PaymentOperator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Paiement Mobile Money (« Payer ») ou son devis : ce qui est réglé (acompte d’une proposition ou échéances),
 * l’opérateur et, pour payer, le numéro qui reçoit la demande de paiement.
 */
class PaymentRequest extends FormRequest
{
    use PhoneFields;

    private ?PaymentOperator $operatorModel = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paying = $this->routeIs('*.payments.store');

        return [
            'proposal_id' => ['required_without:schedule_ids', 'prohibits:schedule_ids', 'nullable', 'uuid'],
            'schedule_ids' => ['required_without:proposal_id', 'nullable', 'array', 'min:1', 'max:20'],
            'schedule_ids.*' => ['uuid', 'distinct'],
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
            'proposal_id.required_without' => 'Indiquez ce que vous payez : proposal_id (acompte) ou schedule_ids (échéances).',
            'schedule_ids.required_without' => 'Indiquez ce que vous payez : proposal_id (acompte) ou schedule_ids (échéances).',
            'proposal_id.prohibits' => 'Payez un acompte ou des échéances, pas les deux.',
            'operator_code.required' => 'Choisissez un moyen de paiement.',
            'operator_code.exists' => 'Moyen de paiement indisponible.',
        ];
    }

    public function operator(): ?PaymentOperator
    {
        return $this->operatorModel ??= PaymentOperator::query()->where('code', (string) $this->input('operator_code'))->first();
    }

    /**
     * @return array{proposal_id?: string, schedule_ids?: list<string>}
     */
    public function target(): array
    {
        return $this->filled('proposal_id') ? ['proposal_id' => (string) $this->input('proposal_id')] : ['schedule_ids' => array_values((array) $this->input('schedule_ids'))];
    }
}
