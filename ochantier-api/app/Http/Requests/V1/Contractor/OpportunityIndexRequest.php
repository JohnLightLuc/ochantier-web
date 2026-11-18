<?php

namespace App\Http\Requests\V1\Contractor;

use App\Support\Contractor\ContractorOpportunities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onglet et recherche de l’écran « Opportunités » : demandes de devis (par défaut) ou offres contractuelles.
 */
class OpportunityIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::in([ContractorOpportunities::QUOTES, ContractorOpportunities::OFFERS])],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['kind.in' => 'Onglet inconnu : quotes ou offers.'];
    }
}
