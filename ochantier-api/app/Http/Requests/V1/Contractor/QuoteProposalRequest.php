<?php

namespace App\Http\Requests\V1\Contractor;

use App\Support\Promoter\QuoteRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Proposer ma prestation » : frais de visite ou d’étude (net, hors frais de service), date et créneau de visite,
 * durée estimée et commentaire.
 */
class QuoteProposalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fee_amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'visit_date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'visit_slot' => ['required', Rule::in(QuoteRequests::SLOTS)],
            'visit_duration' => ['nullable', Rule::in(QuoteRequests::DURATIONS)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fee_amount.required' => 'Indiquez un montant positif.',
            'fee_amount.min' => 'Indiquez un montant positif.',
            'fee_amount.integer' => 'Indiquez un montant positif.',
            'visit_date.required' => 'Choisissez une date de visite.',
            'visit_date.date_format' => 'Choisissez une date de visite.',
            'visit_date.after' => 'La date de visite doit être future.',
            'visit_slot.required' => 'Choisissez un créneau.',
            'visit_slot.in' => 'Choisissez un créneau.',
            'visit_duration.in' => 'Choisissez une durée.',
        ];
    }
}
