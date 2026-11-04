<?php

namespace App\Http\Requests\V1\Admin\Worker;

use App\Enums\AvailabilityStatus;
use App\Support\Admin\WorkerDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des ouvriers du Back Office.
 */
class WorkerIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(WorkerDirectory::STATUSES)],
            'trade_id' => ['nullable', 'uuid'],
            'level' => ['nullable', Rule::in(array_keys(WorkerDirectory::LEVELS))],
            'availability' => ['nullable', Rule::enum(AvailabilityStatus::class)],
            'commune_id' => ['nullable', 'uuid'],
            'commercial_id' => ['nullable', 'uuid'],
            'daily_rate_min' => ['nullable', 'integer', 'min:0'],
            'daily_rate_max' => ['nullable', 'integer', 'min:0'],
            'rating_min' => ['nullable', 'numeric', 'between:0,5'],
            'rating_max' => ['nullable', 'numeric', 'between:0,5'],
            'todo' => ['nullable', Rule::in(array_keys(WorkerDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(WorkerDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
