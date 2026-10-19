<?php

namespace App\Http\Requests\V1\Promoter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Absent » : ouvrier non venu à la date donnée (aujourd’hui par défaut).
 */
class AttendanceAbsenceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignment_id' => ['required', 'uuid'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignment_id.required' => 'Choisissez un ouvrier.',
            'date.date_format' => 'Date invalide (AAAA-MM-JJ).',
        ];
    }
}
