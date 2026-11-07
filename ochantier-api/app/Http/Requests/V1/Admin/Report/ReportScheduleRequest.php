<?php

namespace App\Http\Requests\V1\Admin\Report;

use App\Enums\ExportFormat;
use App\Enums\ReportFrequency;
use App\Support\Admin\ReportCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Programmation d’un rapport.
 */
class ReportScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(ReportCatalog::KINDS))],
            'name' => ['required', 'string', 'max:160'],
            'frequency' => ['required', Rule::enum(ReportFrequency::class)],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'first_run_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nommez ce rapport.',
            'first_run_on.*' => 'Choisissez une date à venir.',
            'recipients.required' => 'Ajoutez au moins un destinataire.',
            'recipients.*.email' => 'Une adresse e-mail n’est pas valide.',
        ];
    }
}
