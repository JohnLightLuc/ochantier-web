<?php

namespace App\Http\Requests\V1\Commercial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Archivage d’un prospect avec un motif normalisé (catégorie abandon_prospect).
 */
class AbandonProspectRequest extends FormRequest
{
    public const REASON_CATEGORY = 'abandon_prospect';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::exists('reason_codes', 'code')->where('category', self::REASON_CATEGORY)->where('is_active', true)],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason_code.required' => 'Choisissez un motif.', 'reason_code.exists' => 'Choisissez un motif de la liste.'];
    }
}
