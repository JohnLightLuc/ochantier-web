<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Rechercher des ouvriers » : début et nombre de jours de travail, puis les besoins par métier (effectif et tarif
 * journalier proposé).
 */
class StaffingRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.trade_id' => ['required', 'uuid', 'distinct', Rule::exists('trades', 'id')->where('is_active', true)],
            'lines.*.headcount' => ['required', 'integer', 'min:1', 'max:100'],
            'lines.*.daily_rate' => ['required', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.required' => 'Renseignez la période de travail.',
            'days.required' => 'Renseignez la période de travail.',
            'days.min' => 'Renseignez la période de travail.',
            'lines.required' => 'Ajoutez au moins un ouvrier à rechercher.',
            'lines.*.trade_id.exists' => 'Ce métier n’est pas proposé.',
            'lines.*.trade_id.distinct' => 'Ce métier est demandé deux fois.',
            'lines.*.headcount.min' => 'Indiquez au moins un ouvrier.',
            'lines.*.daily_rate.required' => 'Indiquez le tarif journalier proposé.',
            'lines.*.daily_rate.min' => 'Indiquez le tarif journalier proposé.',
        ];
    }
}
