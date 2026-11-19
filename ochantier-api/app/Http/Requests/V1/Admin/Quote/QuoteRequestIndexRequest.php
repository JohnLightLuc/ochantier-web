<?php

namespace App\Http\Requests\V1\Admin\Quote;

use App\Support\Admin\QuoteRequestDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des demandes de devis : recherche (référence, chantier, promoteur), métier, ville et statuts
 * affichés (plusieurs séparés par des virgules pour un onglet qui en regroupe plusieurs).
 */
class QuoteRequestIndexRequest extends FormRequest
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
            'status.*' => [Rule::in(array_keys(QuoteRequestDirectory::STATUSES))],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
