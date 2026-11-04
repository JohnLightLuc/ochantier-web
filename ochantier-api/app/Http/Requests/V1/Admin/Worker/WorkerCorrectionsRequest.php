<?php

namespace App\Http\Requests\V1\Admin\Worker;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande de corrections au commercial : pièces à remplacer ou informations du dossier à reprendre.
 */
class WorkerCorrectionsRequest extends FormRequest
{
    /**
     * Informations du dossier qu’une correction peut viser.
     */
    public const FIELDS = ['name', 'phone', 'birth', 'residence', 'trade', 'experience', 'daily_rate', 'emergency_contact'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'corrections' => ['required', 'array', 'min:1', 'max:20'],
            'corrections.*.document_id' => ['nullable', 'uuid', 'required_without:corrections.*.field'],
            'corrections.*.field' => ['nullable', 'in:'.implode(',', self::FIELDS), 'required_without:corrections.*.document_id'],
            'corrections.*.reason' => ['required', 'string', 'min:5', 'max:500'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'corrections.required' => 'Indiquez au moins une correction.',
            'corrections.*.document_id.required_without' => 'Choisissez la pièce ou l’information à corriger.',
            'corrections.*.field.required_without' => 'Choisissez la pièce ou l’information à corriger.',
            'corrections.*.reason.required' => 'Indiquez le motif de la correction.',
            'corrections.*.reason.min' => 'Le motif est trop court.',
        ];
    }
}
