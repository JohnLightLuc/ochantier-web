<?php

namespace App\Http\Requests\V1\Admin\Contractor;

/**
 * Demande de corrections : motif communiqué à l’entrepreneur et, facultativement, pièces du dossier à remplacer.
 */
class ContractorCorrectionsRequest extends ContractorReasonRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'document_ids' => ['nullable', 'array', 'max:20'],
            'document_ids.*' => ['uuid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function documentIds(): array
    {
        return array_values($this->validated('document_ids') ?? []);
    }
}
