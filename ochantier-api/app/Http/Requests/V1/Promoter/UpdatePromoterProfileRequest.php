<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\PromoterProfileKind;
use App\Models\PromoterProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Modifier le profil » : identité, adresse et, pour une entreprise, raison sociale, RCCM, NCC et siège social.
 * Seuls les champs envoyés sont enregistrés. Le numéro de téléphone se change par « Changer de numéro » (code SMS).
 */
class UpdatePromoterProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $profile = $this->profile();
        $kind = $this->input('profile_kind', $profile->profile_kind->value);
        $company = $kind === PromoterProfileKind::Entreprise->value;
        $creatingCompany = $company && $profile->profile_kind !== PromoterProfileKind::Entreprise;
        $communeId = $this->input('commune_id', $profile->commune_id);
        $headquartersCommune = $this->input('company.headquarters.commune_id');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:80'],
            'profile_kind' => ['sometimes', Rule::enum(PromoterProfileKind::class)],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commune_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'neighborhood_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $communeId)],

            'company' => [$company ? ($creatingCompany ? 'required' : 'sometimes') : 'prohibited', 'array'],
            'company.legal_name' => [$creatingCompany ? 'required' : 'sometimes', 'string', 'min:2', 'max:200'],
            'company.trade_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'company.legal_form' => ['sometimes', 'nullable', 'string', 'max:40'],
            'company.rccm' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/-]{4,}$/'],
            'company.ncc' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]{4,}$/'],
            'company.headquarters' => ['sometimes', 'nullable', 'array'],
            'company.headquarters.address' => ['required_with:company.headquarters', 'string', 'max:255'],
            'company.headquarters.commune_id' => ['nullable', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'company.headquarters.neighborhood_id' => ['nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $headquartersCommune)],
            'company.headquarters.location' => ['nullable', 'array'],
            'company.headquarters.location.latitude' => ['required_with:company.headquarters.location', 'numeric', 'between:-90,90'],
            'company.headquarters.location.longitude' => ['required_with:company.headquarters.location', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Renseignez votre prénom.',
            'last_name.required' => 'Renseignez votre nom.',
            'company.required' => 'Indiquez la raison sociale de votre entreprise.',
            'company.legal_name.required' => 'Indiquez la raison sociale de votre entreprise.',
            'company.legal_name.min' => 'Indiquez la raison sociale de votre entreprise.',
            'company.prohibited' => 'Les informations d’entreprise ne concernent que les comptes « Entreprise ».',
            'company.rccm.regex' => 'Saisissez un numéro RCCM valide (ex. CI-ABJ-2024-B-12345).',
            'company.ncc.regex' => 'Saisissez un numéro NCC valide (ex. 2412345 K).',
            'company.headquarters.address.required_with' => 'Indiquez l’adresse du siège social.',
            'neighborhood_id.exists' => 'Ce quartier n’appartient pas à la commune choisie.',
            'company.headquarters.neighborhood_id.exists' => 'Ce quartier n’appartient pas à la commune choisie.',
            'company.headquarters.location.latitude.between' => 'Position GPS invalide.',
            'company.headquarters.location.longitude.between' => 'Position GPS invalide.',
        ];
    }

    public function profile(): PromoterProfile
    {
        return $this->user()->promoterProfile()->firstOrFail();
    }
}
