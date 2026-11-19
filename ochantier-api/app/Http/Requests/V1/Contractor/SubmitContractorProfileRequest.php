<?php

namespace App\Http\Requests\V1\Contractor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Soumettre le dossier » : certification de l’exactitude des informations et accord pour leur traitement.
 */
class SubmitContractorProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['certify' => ['accepted']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['certify.accepted' => 'Votre consentement est nécessaire pour soumettre le dossier.'];
    }
}
