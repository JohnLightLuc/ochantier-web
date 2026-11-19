<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Corriger » un pointage : motif obligatoire, puis heures d’arrivée et de départ, absence ou observation.
 */
class AttendanceCorrectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'arrival_time' => ['nullable', 'date_format:H:i'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'absent' => ['sometimes', 'boolean'],
            'observation' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif de la correction est obligatoire.',
            'reason.min' => 'Le motif de la correction est obligatoire.',
            'arrival_time.date_format' => 'Heure invalide (HH:MM).',
            'departure_time.date_format' => 'Heure invalide (HH:MM).',
        ];
    }
}
