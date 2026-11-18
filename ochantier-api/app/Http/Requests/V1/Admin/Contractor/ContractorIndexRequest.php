<?php

namespace App\Http\Requests\V1\Admin\Contractor;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des entrepreneurs : recherche (nom, référence, téléphone, e-mail), statut du dossier, type de
 * profil et statut du compte.
 */
class ContractorIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'verification_status' => ['nullable', Rule::enum(ContractorVerificationStatus::class)],
            'kind' => ['nullable', Rule::enum(ContractorKind::class)],
            'status' => ['nullable', Rule::enum(ContractorStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
