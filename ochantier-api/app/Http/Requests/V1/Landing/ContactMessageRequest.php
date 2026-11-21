<?php

namespace App\Http\Requests\V1\Landing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Message du formulaire de contact transmis par le site public.
 */
class ContactMessageRequest extends FormRequest
{
    public const PROFILES = ['Promoteur immobilier', 'Entreprise de BTP', 'Technicien chantier', 'Ouvrier', 'Autre'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'profile' => ['required', 'in:'.implode(',', self::PROFILES)],
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:5000'],
            'consent' => ['accepted'],
        ];
    }
}
