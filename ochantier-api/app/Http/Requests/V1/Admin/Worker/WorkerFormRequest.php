<?php

namespace App\Http\Requests\V1\Admin\Worker;

use App\Enums\Gender;
use App\Enums\OfferType;
use App\Enums\PaymentFrequency;
use App\Http\Requests\V1\Commercial\ProspectRequest;
use App\Http\Requests\V1\PhoneFields;
use App\Models\Worker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Création ou modification d’un ouvrier par le Back Office : le formulaire est envoyé complet à chaque fois.
 * Le numéro de l’ouvrier est unique parmi les ouvriers (hors fiches fusionnées).
 */
class WorkerFormRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tradeIds = array_values(array_filter([$this->input('trade_id'), $this->input('secondary_trade_id')], fn ($id) => is_string($id)));

        return [
            'commercial_id' => ['required', 'uuid', Rule::exists('commercial_profiles', 'id')->where('status', 'actif')],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            ...$this->phoneRules(['required']),
            'secondary_phone' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.now()->subYears(ProspectRequest::MINIMUM_AGE)->toDateString(), 'after:1940-01-01'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'residence_commune_id' => ['required', 'uuid', Rule::exists('communes', 'id')->where('is_active', true)],
            'residence_neighborhood_id' => ['nullable', 'uuid', Rule::exists('neighborhoods', 'id')->where('commune_id', $this->input('residence_commune_id'))],
            'residence_address' => ['nullable', 'string', 'max:255'],
            'residence_landmark' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:160'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],
            ...$this->phoneRules(['nullable'], 'emergency_contact_'),

            'trade_id' => ['required', 'uuid', Rule::exists('trades', 'id')->where('is_active', true)],
            'experience_years' => ['required', 'integer', 'min:0', 'max:60'],
            'secondary_trade_id' => ['nullable', 'uuid', 'different:trade_id', Rule::exists('trades', 'id')->where('is_active', true)],
            'secondary_experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'specialty_ids' => ['nullable', 'array', 'max:20'],
            'specialty_ids.*' => ['uuid', 'distinct', Rule::exists('specialties', 'id')->where('is_active', true)->whereIn('trade_id', $tradeIds)],
            'skill_ids' => ['nullable', 'array', 'max:30'],
            'skill_ids.*' => ['uuid', 'distinct', Rule::exists('skills', 'id')->where('is_active', true)->whereIn('trade_id', $tradeIds)],
            'daily_rate' => ['nullable', 'integer', 'min:1000', 'max:1000000'],
            'references' => ['nullable', 'array', 'max:10'],
            'references.*.project' => ['required', 'string', 'max:200'],
            'references.*.role' => ['nullable', 'string', 'max:120'],
            'references.*.employer' => ['nullable', 'string', 'max:160'],
            'references.*.start_date' => ['nullable', 'date'],
            'references.*.end_date' => ['nullable', 'date', 'after_or_equal:references.*.start_date'],

            'mobility_radius_km' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'accepts_long_travel' => ['boolean'],
            'needs_accommodation' => ['boolean'],
            'transport_mode' => ['nullable', 'string', 'max:30'],
            'works_solo' => ['boolean'],
            'works_in_team' => ['boolean'],
            'accepted_offer_types' => ['nullable', 'array'],
            'accepted_offer_types.*' => [Rule::enum(OfferType::class)],
            'accepted_payment_frequencies' => ['nullable', 'array'],
            'accepted_payment_frequencies.*' => [Rule::enum(PaymentFrequency::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $phone = $this->phoneNumber();

            if (! $phone || $validator->errors()->has('phone_number')) {
                return;
            }

            if ($this->phoneNumber('emergency_contact_')?->equals($phone)) {
                $validator->errors()->add('emergency_contact_phone_number', 'Le contact d’urgence doit avoir un autre numéro que l’ouvrier.');
            }

            $taken = Worker::query()->where('phone', $phone->e164())->whereNull('merged_into_worker_id')
                ->when($this->route('worker'), fn ($query, string $id) => $query->whereKeyNot($id))
                ->exists();

            if ($taken) {
                $validator->errors()->add('phone_number', 'Ce numéro est déjà inscrit sur O’CHANTIER.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            ...$this->phoneMessages('emergency_contact_'),
            'commercial_id.required' => 'Choisissez le commercial référent.',
            'commercial_id.exists' => 'Ce commercial n’est plus actif.',
            'first_name.required' => 'Indiquez le prénom.',
            'last_name.required' => 'Indiquez le nom.',
            'residence_commune_id.required' => 'Sélectionnez une commune.',
            'residence_neighborhood_id.exists' => 'Ce quartier n’appartient pas à la commune choisie.',
            'trade_id.required' => 'Choisissez le métier principal.',
            'experience_years.required' => 'Indiquez les années d’expérience.',
            'secondary_trade_id.different' => 'Le métier secondaire doit être différent du métier principal.',
            'birth_date.before_or_equal' => 'L’ouvrier doit avoir au moins '.ProspectRequest::MINIMUM_AGE.' ans.',
            'birth_date.after' => 'Saisissez une date de naissance valide.',
            'specialty_ids.*.exists' => 'Cette spécialité ne correspond pas aux métiers choisis.',
            'skill_ids.*.exists' => 'Cette compétence ne correspond pas aux métiers choisis.',
            'daily_rate.min' => 'Le coût journalier doit être d’au moins 1 000 FCFA.',
            'references.*.project.required' => 'Indiquez le chantier ou le projet.',
            'references.*.end_date.after_or_equal' => 'La fin doit suivre le début.',
        ];
    }
}
