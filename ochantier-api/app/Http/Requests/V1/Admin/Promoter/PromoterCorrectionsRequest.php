<?php

namespace App\Http\Requests\V1\Admin\Promoter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande de corrections : une ou plusieurs corrections, chacune sur une pièce du dossier ou un élément du
 * profil, avec son motif, et un message facultatif pour le promoteur.
 */
class PromoterCorrectionsRequest extends FormRequest
{
    /**
     * Éléments du profil qui peuvent faire l’objet d’une correction.
     */
    public const FIELDS = ['name', 'address', 'company_name', 'rccm', 'ncc', 'headquarters'];

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
            'corrections.*.document_id.required_without' => 'Choisissez la pièce ou l’élément à corriger.',
            'corrections.*.field.required_without' => 'Choisissez la pièce ou l’élément à corriger.',
            'corrections.*.reason.required' => 'Indiquez le motif de la correction.',
            'corrections.*.reason.min' => 'Le motif est trop court.',
        ];
    }

    /**
     * @return list<array{document_id?: string|null, field?: string|null, reason: string}>
     */
    public function corrections(): array
    {
        return array_values($this->validated('corrections'));
    }
}
