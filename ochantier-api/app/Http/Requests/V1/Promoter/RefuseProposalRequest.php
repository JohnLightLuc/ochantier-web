<?php

namespace App\Http\Requests\V1\Promoter;

use App\Support\Promoter\Reservations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Refuser la proposition » : motif (catégorie refus_proposition des référentiels) et précision facultative.
 */
class RefuseProposalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::exists('reason_codes', 'code')->where('category', Reservations::REFUSAL_CATEGORY)->where('is_active', true)],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason_code.required' => 'Choisissez un motif.',
            'reason_code.exists' => 'Motif inconnu.',
            'comment.max' => 'La précision ne doit pas dépasser 500 caractères.',
        ];
    }
}
