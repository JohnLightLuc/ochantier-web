<?php

namespace App\Http\Requests\V1\Admin\Payout;

use App\Http\Requests\V1\PhoneFields;
use App\Models\PaymentOperator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Numéro Mobile Money proposé pour les reversements d’un ouvrier.
 */
class PaymentMethodRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operator_id' => ['required', 'uuid', Rule::exists('payment_operators', 'id')->where('is_active', true)->where('supports_payout', true)],
            ...$this->phoneRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $operator = $validator->errors()->has('operator_id') ? null : $this->operator();
                $phone = $this->phoneNumber();

                if ($operator?->number_pattern && $phone?->dialCode === '225' && ! $validator->errors()->has('phone_number') && ! preg_match('/'.$operator->number_pattern.'/', $phone->number)) {
                    $validator->errors()->add('phone_number', 'Ce numéro n’est pas un numéro '.$operator->name.'.');
                }
            },
        ];
    }

    public function operator(): PaymentOperator
    {
        return PaymentOperator::query()->findOrFail($this->input('operator_id'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['operator_id.required' => 'Choisissez l’opérateur.', 'operator_id.exists' => 'Cet opérateur ne permet pas les reversements.', ...$this->phoneMessages()];
    }
}
