<?php

namespace App\Http\Requests\V1\Contractor;

use App\Enums\ContractorResponseMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Répondre à l’offre » : offre conforme (conditions du promoteur reprises) ou contre-proposition (prix net, début
 * et fin des travaux), avec la taille de l’équipe et des précisions. draft = true enregistre un brouillon.
 */
class OfferResponseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $counter = $this->input('mode') === ContractorResponseMode::ContreProposition->value;

        return [
            'mode' => ['required', Rule::enum(ContractorResponseMode::class)],
            'amount' => [$counter ? 'required' : 'prohibited', 'integer', 'min:1', 'max:100000000000'],
            'start_date' => [$counter ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after:today'],
            'end_date' => [$counter ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after:start_date'],
            'team_size' => ['required', 'integer', 'min:1', 'max:500'],
            'message' => ['nullable', 'string', 'max:2000'],
            'draft' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'Choisissez « Accepter les conditions » ou « Contre-proposition ».',
            'mode.enum' => 'Choisissez « Accepter les conditions » ou « Contre-proposition ».',
            'amount.required' => 'Le prix proposé doit être positif.',
            'amount.min' => 'Le prix proposé doit être positif.',
            'amount.integer' => 'Le prix proposé doit être positif.',
            'start_date.required' => 'La date de début doit être future.',
            'start_date.after' => 'La date de début doit être future.',
            'end_date.required' => 'La date de fin doit être postérieure au début.',
            'end_date.after' => 'La date de fin doit être postérieure au début.',
            'prohibited' => 'Une offre conforme reprend les conditions du promoteur : passez en contre-proposition pour les modifier.',
            'team_size.required' => 'Indiquez la taille de votre équipe.',
            'team_size.min' => 'Indiquez la taille de votre équipe.',
        ];
    }
}
