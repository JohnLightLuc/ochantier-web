<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Affectation » : période (dans celle du forfait) puis, pour chaque ouvrier choisi, son rôle sur le chantier et
 * le tarif journalier convenu (payé par l’entrepreneur, hors plateforme).
 */
class AssignWorkersRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'workers' => ['required', 'array', 'min:1', 'max:30'],
            'workers.*.worker_id' => ['required', 'uuid', 'distinct'],
            'workers.*.role' => ['required', 'string', 'min:2', 'max:120'],
            'workers.*.daily_rate' => ['required', 'integer', 'min:1', 'max:10000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.required' => 'Renseignez la période.',
            'end_date.required' => 'Renseignez la période.',
            'end_date.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'workers.required' => 'Choisissez au moins un ouvrier.',
            'workers.*.worker_id.distinct' => 'Cet ouvrier est choisi deux fois.',
            'workers.*.role.required' => 'Indiquez le rôle de l’ouvrier.',
            'workers.*.role.min' => 'Indiquez le rôle de l’ouvrier.',
            'workers.*.daily_rate.required' => 'Le prix journalier doit être positif.',
            'workers.*.daily_rate.min' => 'Le prix journalier doit être positif.',
        ];
    }
}
