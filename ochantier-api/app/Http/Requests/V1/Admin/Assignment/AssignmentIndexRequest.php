<?php

namespace App\Http\Requests\V1\Admin\Assignment;

use App\Support\Admin\AssignmentDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des affectations du Back Office.
 */
class AssignmentIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(AssignmentDirectory::STATUSES)],
            'trade_id' => ['nullable', 'uuid'],
            'commune_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'worker_id' => ['nullable', 'uuid'],
            'todo' => ['nullable', Rule::in(array_keys(AssignmentDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(AssignmentDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
