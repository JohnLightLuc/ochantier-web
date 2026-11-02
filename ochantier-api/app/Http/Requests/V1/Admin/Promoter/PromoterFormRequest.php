<?php

namespace App\Http\Requests\V1\Admin\Promoter;

use App\Enums\PromoterProfileKind;
use App\Enums\UserType;
use App\Http\Requests\V1\PhoneFields;
use App\Models\PromoterProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’un promoteur par le Back Office : le formulaire complet est envoyé à chaque fois.
 *
 * Téléphone ou e-mail (l’un des deux au moins), uniques parmi les comptes promoteurs. Pour une entreprise, la raison
 * sociale est obligatoire ; RCCM, NCC et siège peuvent être complétés plus tard.
 */
class PromoterFormRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->promoterUserId();
        $company = $this->input('profile_kind') === PromoterProfileKind::Entreprise->value;
        $communeId = $this->input('commune_id');
        $headquartersCommune = $this->input('company.headquarters.commune_id');

        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            ...$this->phoneRules(['required_without:email'], uniqueFor: UserType::Promoter, ignoreId: $userId),
            'email' => ['required_without:phone_number', 'nullable', 'email', 'max:160', Rule::unique('users', 'email')->where('user_type', UserType::Promoter->value)->ignore($userId)],
            'profile_kind' => ['required', Rule::enum(PromoterProfileKind::class)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'commune_id' => ['nullable', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'neighborhood_id' => ['nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $communeId)],
            'send_invitation' => ['sometimes', 'boolean'],

            'company' => [$company ? 'required' : 'prohibited', 'array'],
            'company.legal_name' => [$company ? 'required' : 'nullable', 'string', 'min:2', 'max:200'],
            'company.trade_name' => ['nullable', 'string', 'max:200'],
            'company.legal_form' => ['nullable', 'string', 'max:40'],
            'company.rccm' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/-]{4,}$/'],
            'company.ncc' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]{4,}$/'],
            'company.headquarters' => ['nullable', 'array'],
            'company.headquarters.address' => ['required_with:company.headquarters', 'string', 'max:255'],
            'company.headquarters.commune_id' => ['nullable', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'company.headquarters.neighborhood_id' => ['nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $headquartersCommune)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'required' => 'Ce champ est obligatoire.',
            'phone_number.required_without' => 'Saisissez un numéro de téléphone ou une adresse e-mail.',
            'email.required_without' => 'Saisissez un numéro de téléphone ou une adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
            'company.required' => 'Renseignez les informations de l’entreprise.',
            'company.prohibited' => 'Les informations d’entreprise ne concernent que les comptes entreprise.',
            'company.legal_name.required' => 'Indiquez la raison sociale.',
            'company.rccm.regex' => 'Saisissez un numéro RCCM valide.',
            'company.ncc.regex' => 'Saisissez un numéro NCC valide.',
            'company.headquarters.address.required_with' => 'Indiquez l’adresse du siège.',
            'exists' => 'Valeur non reconnue.',
            'max' => 'Ce champ est trop long.',
            'enum' => 'Valeur non autorisée.',
        ];
    }

    /**
     * Le promoteur recevra-t-il l’invitation à activer son compte ? (oui par défaut)
     */
    public function sendsInvitation(): bool
    {
        return $this->boolean('send_invitation', true);
    }

    /**
     * Compte modifié (ignoré dans les contrôles d’unicité), ou null à la création.
     */
    private function promoterUserId(): ?string
    {
        $id = $this->route('promoter');

        return is_string($id) && str($id)->isUuid() ? PromoterProfile::query()->whereKey($id)->value('user_id') : null;
    }
}
