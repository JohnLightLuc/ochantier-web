<?php

namespace App\Http\Requests\V1\Admin\Promoter;

use App\Enums\PromoterProfileKind;
use App\Support\Admin\PromoterDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recherche, filtres et tri de la liste des promoteurs (et de son export).
 */
class PromoterIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(PromoterDirectory::STATUSES)],
            'kind' => ['nullable', Rule::enum(PromoterProfileKind::class)],
            'commune_id' => ['nullable', 'uuid'],
            'registered' => ['nullable', 'regex:/^(30j|20\d{2})$/'],
            'todo' => ['nullable', Rule::in(array_keys(PromoterDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(PromoterDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
