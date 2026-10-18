<?php

namespace App\Http\Requests\V1;

use App\Enums\WorkerStatus;
use App\Support\Catalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification du profil par l’ouvrier. L’identité (nom, date de naissance, pièce) n’est modifiable qu’avant
 * la vérification, ou après un rejet ; le numéro principal sert d’identifiant et ne change pas ici.
 */
class UpdateWorkerProfileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $identity = in_array($this->user()->status, [WorkerStatus::Incomplete, WorkerStatus::Rejected], true) ? 'sometimes' : 'prohibited';

        return [
            'first_name' => [$identity, 'string', 'max:80'],
            'last_name' => [$identity, 'string', 'max:80'],
            'birth_date' => [$identity, 'nullable', 'date', 'before_or_equal:'.now()->subYears(18)->format('Y-m-d')],
            'id_type' => [$identity, 'nullable', Rule::in(Catalog::ID_TYPES)],
            'id_number' => [$identity, 'nullable', 'string', 'max:40'],
            'id_issued_at' => [$identity, 'nullable', 'date'],
            'id_expires_at' => [$identity, 'nullable', 'date', 'after:today'],
            'photo' => ['sometimes', 'image', 'max:5120'],
            'gender' => ['sometimes', 'nullable', Rule::in(['Homme', 'Femme'])],
            'nationality' => ['sometimes', 'nullable', Rule::in(Catalog::NATIONALITIES)],
            'phone_secondary' => ['sometimes', 'nullable', 'string', new PhoneRule],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commune' => ['sometimes', Rule::in(Catalog::COMMUNES)],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergency_phone' => ['sometimes', 'nullable', 'string', new PhoneRule],
            'trade' => ['sometimes', Rule::in(Catalog::trades())],
            'secondary_trades' => ['sometimes', 'array'],
            'secondary_trades.*' => [Rule::in(Catalog::trades())],
            'specialties' => ['sometimes', 'array'],
            'specialties.*' => ['string', 'max:120'],
            'skills' => ['sometimes', 'array'],
            'skills.*' => ['string', 'max:120'],
            'qualification' => ['sometimes', 'nullable', Rule::in(Catalog::QUALIFICATIONS)],
            'experience_years' => ['sometimes', 'integer', 'between:0,50'],
            'experience_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'daily_rate' => ['sometimes', 'integer', 'between:3000,100000'],
            'mobility' => ['sometimes', 'nullable', Rule::in(Catalog::MOBILITIES)],
            'zones' => ['sometimes', 'array'],
            'zones.*' => [Rule::in(Catalog::COMMUNES)],
            'works_solo' => ['sometimes', 'boolean'],
            'works_in_team' => ['sometimes', 'boolean'],
            'accepts_contracts' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prohibited' => 'Profil vérifié : contactez le support pour modifier votre identité.',
        ];
    }
}
