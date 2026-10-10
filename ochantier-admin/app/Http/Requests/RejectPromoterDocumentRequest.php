<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectPromoterDocumentRequest extends FormRequest
{
    public const CATEGORIES = ['Document illisible', 'Document expiré', 'Informations incohérentes', 'Mauvais document', 'Autre'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Ce champ est obligatoire (10 caractères minimum).',
            'reason.min' => 'Ce champ est obligatoire (10 caractères minimum).',
        ];
    }
}
