<?php

namespace App\Http\Requests\V1\Admin\Contractor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Décision motivée sur un entrepreneur : refus du dossier ou suspension du compte.
 */
class ContractorReasonRequest extends FormRequest
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
