<?php

namespace App\Http\Requests\V1\Promoter;

use App\Support\Promoter\SiteReplacements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Remplacer un ouvrier » : motif (catégorie remplacement, motifs ouverts au promoteur) et précision facultative.
 */
class ReplacementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in(SiteReplacements::PROMOTER_REASONS), Rule::exists('reason_codes', 'code')->where('category', SiteReplacements::REASON_CATEGORY)->where('is_active', true)],
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
            'reason_code.in' => 'Motif inconnu.',
            'reason_code.exists' => 'Motif inconnu.',
            'comment.max' => 'La précision ne doit pas dépasser 500 caractères.',
        ];
    }
}
