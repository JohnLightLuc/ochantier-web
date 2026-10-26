<?php

namespace App\Http\Requests\V1\Commercial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Demander un changement » de zone d’activité : communes souhaitées.
 */
class ZoneChangeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'commune_ids' => ['required', 'array', 'min:1', 'max:10'],
            'commune_ids.*' => ['uuid', 'distinct', Rule::exists('communes', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'commune_ids.required' => 'Sélectionnez au moins une commune.',
            'commune_ids.min' => 'Sélectionnez au moins une commune.',
            'commune_ids.max' => 'Sélectionnez au plus 10 communes.',
            'commune_ids.*.exists' => 'Commune inconnue.',
            'commune_ids.*.uuid' => 'Commune inconnue.',
        ];
    }
}
