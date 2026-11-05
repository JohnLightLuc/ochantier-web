<?php

namespace App\Http\Requests\V1\Admin\Commercial;

use App\Enums\CommercialProfileStatus;
use App\Enums\UserType;
use App\Http\Requests\V1\PhoneFields;
use App\Models\CommercialProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d’un commercial par le Back Office. Numéro de téléphone (identifiant de connexion) et
 * e-mail uniques parmi les commerciaux. Les zones se choisissent à la création.
 */
class CommercialFormRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('commercial');
        $userId = $id ? CommercialProfile::query()->whereKey($id)->value('user_id') : null;
        $commune = Rule::exists('communes', 'id')->where('is_active', true);

        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            ...$this->phoneRules(uniqueFor: UserType::Commercial, ignoreId: $userId),
            'secondary_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('users', 'email')->where('user_type', UserType::Commercial->value)->ignore($userId)],
            'address' => ['nullable', 'string', 'max:255'],
            'started_at' => ['required', 'date'],
            'supervisor_id' => ['nullable', 'uuid', Rule::exists('commercial_profiles', 'id')->where('status', CommercialProfileStatus::Actif->value)->whereNull('deleted_at'), Rule::notIn(array_filter([$id]))],
            'is_supervisor' => ['boolean'],
            'primary_commune_id' => [$id ? 'prohibited' : 'required', 'uuid', $commune],
            'secondary_commune_ids' => [$id ? 'prohibited' : 'nullable', 'array', 'max:10'],
            'secondary_commune_ids.*' => ['uuid', $commune],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'first_name.required' => 'Indiquez le prénom.',
            'last_name.required' => 'Indiquez le nom.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre commercial.',
            'started_at.required' => 'Indiquez la date de début.',
            'supervisor_id.exists' => 'Choisissez un superviseur actif.',
            'supervisor_id.not_in' => 'Un commercial ne peut pas être son propre superviseur.',
            'primary_commune_id.required' => 'Choisissez la zone principale.',
        ];
    }
}
