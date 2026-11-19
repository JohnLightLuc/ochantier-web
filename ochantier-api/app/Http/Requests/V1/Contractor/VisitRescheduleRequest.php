<?php

namespace App\Http\Requests\V1\Contractor;

use App\Support\Promoter\QuoteRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Signaler un empêchement » : motif, nouvelle date et créneau proposés au promoteur.
 */
class VisitRescheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'slot' => ['required', Rule::in(QuoteRequests::SLOTS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Motif obligatoire.',
            'reason.min' => 'Motif obligatoire.',
            'date.required' => 'Date future obligatoire.',
            'date.date_format' => 'Date future obligatoire.',
            'date.after' => 'Date future obligatoire.',
            'slot.required' => 'Choisissez un créneau.',
            'slot.in' => 'Choisissez un créneau.',
        ];
    }
}
