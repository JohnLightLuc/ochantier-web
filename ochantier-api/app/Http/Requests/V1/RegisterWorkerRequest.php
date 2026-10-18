<?php

namespace App\Http\Requests\V1;

use App\Enums\AppProfile;
use App\Support\Catalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inscription d’un ouvrier depuis l’application (ou par un commercial depuis l’Application Commercial).
 */
class RegisterWorkerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', new PhoneRule(AppProfile::Worker)],
            'gender' => ['nullable', Rule::in(['Homme', 'Femme'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(18)->format('Y-m-d')],
            'nationality' => ['nullable', Rule::in(Catalog::NATIONALITIES)],
            'commune' => ['required', Rule::in(Catalog::COMMUNES)],
            'trade' => ['required', Rule::in(Catalog::trades())],
            'secondary_trades' => ['array'],
            'secondary_trades.*' => [Rule::in(Catalog::trades())],
            'qualification' => ['nullable', Rule::in(Catalog::QUALIFICATIONS)],
            'experience_years' => ['nullable', 'integer', 'between:0,50'],
            'daily_rate' => ['nullable', 'integer', 'between:3000,100000'],
            'email' => ['nullable', 'email', 'max:160'],
            'sales_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'birth_date.before_or_equal' => 'L’ouvrier doit avoir au moins 18 ans.',
            'in' => 'Valeur non proposée.',
        ];
    }
}
