<?php

namespace App\Http\Requests\V1\Contractor;

use App\Enums\ContractorKind;
use App\Http\Requests\V1\PhoneFields;
use App\Models\Contractor;
use App\Support\Contractor\ContractorAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Brouillon du dossier (« Dossier entreprise (KYB) », « Dossier indépendant (KYC) », « Modifier mon dossier ») :
 * seuls les champs envoyés sont enregistrés. Les champs d’entreprise ne concernent que le profil entreprise, ceux
 * d’identité que le profil indépendant.
 */
class UpdateContractorProfileRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->kind() === ContractorKind::Entreprise;
        $forCompany = fn (array $rules) => $company ? ['sometimes', ...$rules] : ['prohibited'];
        $forPerson = fn (array $rules) => $company ? ['prohibited'] : ['sometimes', ...$rules];

        return [
            'kind' => ['sometimes', Rule::enum(ContractorKind::class)],

            'legal_name' => $forCompany(['required', 'string', 'min:2', 'max:200']),
            'legal_form' => $forCompany(['required', Rule::in(ContractorAccount::LEGAL_FORMS)]),
            'rccm' => $forCompany(['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/-]{4,}$/']),
            'ncc' => $forCompany(['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]{4,}$/']),
            'representative_name' => $forCompany(['required', 'string', 'min:2', 'max:200']),

            'last_name' => $forPerson(['required', 'string', 'max:80']),
            'first_name' => $forPerson(['required', 'string', 'max:80']),
            'birth_date' => $forPerson(['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after:'.now()->subYears(100)->toDateString()]),

            ...$this->phoneRules(['sometimes']),
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'city_id' => ['sometimes', 'required', 'uuid', Rule::exists('cities', 'id')->where('is_active', true)],
            'address' => ['sometimes', 'required', 'string', 'max:255'],

            'trade_ids' => ['sometimes', 'array', 'min:1', 'max:10'],
            'trade_ids.*' => ['uuid', 'distinct', Rule::exists('trades', 'id')->where('is_active', true)],
            'zone_city_ids' => ['sometimes', 'array', 'max:20'],
            'zone_city_ids.*' => ['uuid', 'distinct', Rule::exists('cities', 'id')->where('is_active', true)],
            'experience_years' => ['sometimes', 'required', 'integer', 'between:0,60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'prohibited' => 'Ce champ ne concerne pas votre type de profil.',
            'legal_name.required' => 'Raison sociale obligatoire.',
            'legal_name.min' => 'Raison sociale obligatoire.',
            'legal_form.required' => 'Choisissez la forme juridique.',
            'legal_form.in' => 'Choisissez la forme juridique.',
            'rccm.regex' => 'Saisissez un numéro RCCM valide (ex. CI-ABJ-2024-B-12345).',
            'ncc.regex' => 'Saisissez un identifiant fiscal valide (ex. 2412345 K).',
            'representative_name.required' => 'Nom du représentant légal obligatoire.',
            'representative_name.min' => 'Nom du représentant légal obligatoire.',
            'last_name.required' => 'Nom obligatoire.',
            'first_name.required' => 'Prénoms obligatoires.',
            'birth_date.required' => 'Date de naissance obligatoire.',
            'birth_date.before_or_equal' => 'Vous devez être majeur.',
            'birth_date.after' => 'Date de naissance invalide.',
            'email.email' => 'Email invalide.',
            'city_id.required' => 'Choisissez une ville.',
            'city_id.exists' => 'Choisissez une ville.',
            'address.required' => 'Adresse obligatoire.',
            'trade_ids.min' => 'Choisissez au moins un métier.',
            'trade_ids.*.exists' => 'Ce métier n’est pas proposé.',
            'zone_city_ids.*.exists' => 'Cette ville n’est pas proposée.',
            'experience_years.required' => 'Indiquez un nombre d’années valide.',
            'experience_years.integer' => 'Indiquez un nombre d’années valide.',
            'experience_years.between' => 'Indiquez un nombre d’années valide.',
        ];
    }

    /**
     * Champs validés ; le téléphone envoyé est remplacé par le numéro normalisé (phone).
     *
     * @return array<string, mixed>
     */
    public function profileData(): array
    {
        $data = collect($this->validated())->except(['dial_code', 'phone_number'])->all();

        if ($this->has('phone_number')) {
            $data['phone'] = $this->phoneNumber();
        }

        return $data;
    }

    /**
     * Type de profil visé : celui envoyé, sinon celui du dossier.
     */
    public function kind(): ?ContractorKind
    {
        return ContractorKind::tryFrom((string) $this->input('kind')) ?? $this->contractor()?->kind;
    }

    public function contractor(): ?Contractor
    {
        return $this->user()->contractor;
    }
}
