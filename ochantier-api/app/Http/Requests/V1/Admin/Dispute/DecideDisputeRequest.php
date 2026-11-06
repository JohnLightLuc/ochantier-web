<?php

namespace App\Http\Requests\V1\Admin\Dispute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Décision motivée sur un litige ou un recours, avec un remboursement éventuel.
 */
class DecideDisputeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'min:10', 'max:3000'],
            'refund_amount' => ['nullable', 'integer', 'min:1'],
            'financial_note' => ['nullable', 'string', 'max:1000'],
            'outcome' => [$this->routeIs('*.appeal-decision') ? 'required' : 'prohibited', Rule::in(['confirmee', 'annulee'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Motivez la décision.',
            'decision.min' => 'La motivation est trop courte.',
            'refund_amount.*' => 'Indiquez un montant en FCFA.',
            'outcome.required' => 'Indiquez si la décision est confirmée ou annulée.',
        ];
    }
}
