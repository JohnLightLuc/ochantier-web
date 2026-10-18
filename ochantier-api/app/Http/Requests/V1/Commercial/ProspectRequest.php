<?php

namespace App\Http\Requests\V1\Commercial;

use App\Enums\Gender;
use App\Enums\ProspectStatus;
use App\Http\Requests\V1\PhoneFields;
use App\Models\Prospect;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Prospect et dossier ouvrier (« Nouveau prospect », « Compléter le dossier ») : identité et coordonnées, activité et
 * expérience, contact d’urgence. En modification, seuls les champs envoyés sont enregistrés.
 */
class ProspectRequest extends FormRequest
{
    use PhoneFields;

    /**
     * Âge minimum d’un ouvrier inscrit.
     */
    public const MINIMUM_AGE = 18;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $tradeIds = array_values(array_filter([
            $this->input('trade_id', $this->prospect()?->presumed_trade_id),
            $this->input('secondary_trade_id', $this->prospect()?->draftWorker?->workerTrades()->where('is_primary', false)->value('trade_id')),
        ], fn ($id) => is_string($id)));

        return [
            'client_uuid' => [$creating ? 'nullable' : 'prohibited', 'uuid'],
            'contact_consent' => [$creating ? 'accepted' : 'prohibited'],
            'lock_version' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in([ProspectStatus::Nouveau->value, ProspectStatus::AContacter->value, ProspectStatus::RendezVous->value])],

            'first_name' => [$required, 'string', 'max:80'],
            'last_name' => [$required, 'string', 'max:80'],
            ...$this->phoneRules($creating ? ['required'] : ['sometimes', 'required']),
            'commune_id' => [$required, 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'neighborhood_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $this->input('commune_id', $this->prospect()?->commune_id))],
            'trade_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('trades', 'id')->where('is_active', true)],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],

            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.now()->subYears(self::MINIMUM_AGE)->toDateString(), 'after:1940-01-01'],
            'birth_place' => ['sometimes', 'nullable', 'string', 'max:120'],
            'daily_rate' => ['sometimes', 'nullable', 'integer', 'min:1000', 'max:1000000'],
            'secondary_trade_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('trades', 'id')->where('is_active', true)],
            'secondary_experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'specialty_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'specialty_ids.*' => ['uuid', 'distinct', Rule::exists('specialties', 'id')->where('is_active', true)->whereIn('trade_id', $tradeIds)],
            'skill_ids' => ['sometimes', 'nullable', 'array', 'max:30'],
            'skill_ids.*' => ['uuid', 'distinct', Rule::exists('skills', 'id')->where('is_active', true)->whereIn('trade_id', $tradeIds)],
            'experiences' => ['sometimes', 'nullable', 'array', 'max:10'],
            'experiences.*' => ['string', 'max:200'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            ...$this->phoneRules(['sometimes'], 'emergency_contact_'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $phone = $this->phoneNumber() ?? ($this->prospect() ? PhoneNumber::of($this->prospect()) : null);

                if ($this->phoneNumber('emergency_contact_')?->equals($phone)) {
                    $validator->errors()->add('emergency_contact_phone_number', 'Le contact d’urgence doit avoir un autre numéro que l’ouvrier.');
                }

                $secondary = $this->input('secondary_trade_id');

                if ($secondary && $secondary === $this->input('trade_id', $this->prospect()?->presumed_trade_id)) {
                    $validator->errors()->add('secondary_trade_id', 'Le métier secondaire doit être différent du métier principal.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            ...$this->phoneMessages('emergency_contact_'),
            'contact_consent.accepted' => 'Cochez l’accord de contact pour continuer.',
            'first_name.required' => 'Indiquez le prénom.',
            'last_name.required' => 'Indiquez le nom.',
            'commune_id.required' => 'Sélectionnez une commune.',
            'birth_date.before_or_equal' => 'L’ouvrier doit avoir au moins '.self::MINIMUM_AGE.' ans.',
            'birth_date.after' => 'Saisissez une date de naissance valide.',
            'neighborhood_id.exists' => 'Ce quartier n’appartient pas à la commune choisie.',
            'specialty_ids.*.exists' => 'Cette spécialité ne correspond pas aux métiers choisis.',
            'skill_ids.*.exists' => 'Cette compétence ne correspond pas aux métiers choisis.',
            'daily_rate.min' => 'Le coût journalier doit être d’au moins 1 000 FCFA.',
            'lock_version.integer' => 'Version invalide.',
        ];
    }

    private function prospect(): ?Prospect
    {
        $prospect = $this->route('prospect');

        return $prospect instanceof Prospect ? $prospect : null;
    }
}
