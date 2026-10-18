<?php

namespace App\Http\Requests\V1;

use App\Models\Dispute;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Réclamation sur un paiement ou une journée de pointage (une des deux références).
 */
class ClaimRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment' => ['required_without:attendance', 'nullable', 'string'],
            'attendance' => ['required_without:payment', 'nullable', 'string'],
            'category' => ['required', Rule::in(config('admin.quality.categories'))],
            'subject' => ['required', 'string', 'min:8', 'max:150'],
            'description' => ['required', 'string', 'min:15', 'max:3000'],
            'priority' => ['nullable', Rule::in(Dispute::PRIORITIES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required_without' => 'Indiquez le paiement ou le pointage concerné.',
            'subject.min' => 'Objet trop court (8 caractères minimum).',
            'description.min' => 'Décrivez le problème (15 caractères minimum).',
        ];
    }
}
