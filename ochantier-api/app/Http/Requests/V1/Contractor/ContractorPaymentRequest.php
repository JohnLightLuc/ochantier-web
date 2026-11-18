<?php

namespace App\Http\Requests\V1\Contractor;

use App\Http\Requests\V1\PhoneFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paiement Mobile Money de l’entrepreneur : opérateur et numéro qui paie. Sans numéro (détail du montant), seul
 * l’opérateur est demandé.
 */
class ContractorPaymentRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operator_code' => ['required', 'string', 'max:30'],
            ...$this->phoneRules($this->routeIs('*.pay') ? ['required'] : ['nullable']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->phoneMessages(), 'operator_code.required' => 'Choisissez un opérateur Mobile Money.'];
    }
}
