<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création administrative exceptionnelle d’un chantier (les chantiers sont normalement créés par les promoteurs).
 */
class SiteRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    public const TYPES = ['Maison individuelle', 'Immeuble', 'Résidence', 'Bureau', 'Commerce', 'Entrepôt', 'Usine', 'Infrastructure publique', 'Réhabilitation', 'Rénovation', 'Autre'];

    /**
     * Communes proposées : celles placées sur la carte.
     *
     * @return array<int, string>
     */
    public static function communes(): array
    {
        return array_keys(Site::COORDINATES);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['required_workers' => preg_replace('/\s+/u', '', (string) $this->input('required_workers'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('sites', 'name')],
            'promoter' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(self::TYPES)],
            'commune' => ['required', Rule::in(self::communes())],
            'required_workers' => ['required', 'integer', 'min:1', 'max:500'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'justification' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le libellé est obligatoire.',
            'name.unique' => 'Un chantier porte déjà ce libellé.',
            'required_workers.*' => 'Nombre entier positif.',
            'promoter.required' => 'Le promoteur est obligatoire.',
            'type.*' => 'Choisissez un type de construction.',
            'commune.*' => 'Choisissez une commune de la liste.',
            'starts_on.*' => 'La date de début est obligatoire.',
            'ends_on.required' => 'La date de fin est obligatoire.',
            'ends_on.date' => 'La date de fin est obligatoire.',
            'ends_on.after' => 'La fin doit suivre le début.',
            'justification.*' => 'La justification est obligatoire (10 caractères minimum).',
        ];
    }
}
