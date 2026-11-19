<?php

namespace App\Http\Requests\V1\Admin\Contractor;

use App\Enums\ContractorKind;
use App\Enums\ContractorStatus;
use App\Enums\ContractorVerificationStatus;
use App\Http\Controllers\Api\V1\Admin\Contractor\ContractorController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des entrepreneurs : recherche (nom, référence, téléphone, e-mail), statut du dossier, type de
 * profil, statut du compte, état affiché (onglet), métier et zone d’intervention.
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
            'state' => ['nullable', Rule::in(ContractorController::STATES)],
            'trade' => ['nullable', 'string', 'max:50'],
            'zone_city_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
