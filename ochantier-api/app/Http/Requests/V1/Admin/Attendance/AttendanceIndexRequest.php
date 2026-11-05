<?php

namespace App\Http\Requests\V1\Admin\Attendance;

use App\Support\Admin\AttendanceDirectory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste et de l’export des pointages du Back Office.
 */
class AttendanceIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(AttendanceDirectory::STATUSES)],
            'trade_id' => ['nullable', 'uuid'],
            'commune_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'worker_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'todo' => ['nullable', Rule::in(array_keys(AttendanceDirectory::TODO))],
            'sort' => ['nullable', Rule::in(array_keys(AttendanceDirectory::SORTS))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
