<?php

namespace App\Http\Requests\V1\Admin\ContractOffer;

use App\Support\Admin\ContractOfferDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des offres contractuelles : recherche (référence, chantier, promoteur), métier du devis lié,
 * ville et statuts affichés (plusieurs séparés par des virgules).
 */
class ContractOfferIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => array_values(array_filter(explode(',', $this->input('status'))))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'trade' => ['nullable', 'string', 'max:50'],
            'city_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(array_keys(ContractOfferDirectory::STATUSES))],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
