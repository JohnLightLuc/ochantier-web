<?php

namespace App\Http\Requests\V1\Admin\Promoter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Décision motivée : refus du dossier, rejet d’une pièce, suspension du compte.
 */
class PromoterReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indiquez le motif.',
            'reason.min' => 'Le motif est trop court.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
