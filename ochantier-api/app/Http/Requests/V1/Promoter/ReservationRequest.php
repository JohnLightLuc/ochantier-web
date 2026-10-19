<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\WorkerVerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Nouvelle réservation » sur un chantier publié : besoins par métier et/ou ouvriers choisis, et dates de mission
 * (facultatives : à défaut, celles du chantier).
 */
class ReservationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'needs' => ['sometimes', 'array', 'max:20'],
            'needs.*.trade_id' => ['required', 'uuid', 'distinct', Rule::exists('trades', 'id')->where('is_active', true)],
            'needs.*.headcount' => ['required', 'integer', 'min:0', 'max:50'],
            'needs.*.daily_rate' => ['nullable', 'integer', 'min:1000', 'max:1000000'],
            'worker_ids' => ['sometimes', 'array', 'max:50'],
            'worker_ids.*' => ['uuid', 'distinct', Rule::exists('workers', 'id')->where('verification_status', WorkerVerificationStatus::Valide->value)->whereNull('merged_into_worker_id')->whereNull('deleted_at')],
            'start_date' => ['required_with:end_date', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required_with:start_date', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.after_or_equal' => 'Choisissez des dates de mission valides.',
            'end_date.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'start_date.required_with' => 'Indiquez la date de début de la mission.',
            'end_date.required_with' => 'Indiquez la date de fin de la mission.',
            'needs.*.trade_id.distinct' => 'Ce métier figure déjà dans les besoins.',
            'needs.*.daily_rate.min' => 'Le tarif journalier doit être d’au moins 1 000 FCFA.',
            'worker_ids.*.exists' => 'Cet ouvrier n’est plus disponible à la réservation.',
        ];
    }
}
