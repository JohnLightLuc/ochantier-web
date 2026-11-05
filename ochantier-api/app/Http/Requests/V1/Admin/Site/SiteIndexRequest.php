<?php

namespace App\Http\Requests\V1\Admin\Site;

use App\Enums\Priority;
use App\Enums\SiteStatus;
use App\Support\Admin\SiteDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des chantiers du Back Office.
 */
class SiteIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(SiteStatus::class)],
            'archived' => ['nullable', 'boolean'],
            'promoter_id' => ['nullable', 'uuid'],
            'commune_id' => ['nullable', 'uuid'],
            'construction_type_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'period' => ['nullable', Rule::in(SiteDirectory::PERIODS)],
            'todo' => ['nullable', Rule::in(array_keys(SiteDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(SiteDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
