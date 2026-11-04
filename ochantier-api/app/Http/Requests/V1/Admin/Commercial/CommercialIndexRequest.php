<?php

namespace App\Http\Requests\V1\Admin\Commercial;

use App\Support\Admin\CommercialDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des commerciaux du Back Office.
 */
class CommercialIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(array_keys(CommercialDirectory::STATUSES))],
            'commune_id' => ['nullable', 'uuid'],
            'supervisor_id' => ['nullable', 'uuid'],
            'period' => ['nullable', Rule::in([...array_keys(CommercialDirectory::PERIODS), 'inactifs'])],
            'todo' => ['nullable', Rule::in(array_keys(CommercialDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(CommercialDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
