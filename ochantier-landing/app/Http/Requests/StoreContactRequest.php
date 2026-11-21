<?php

namespace App\Http\Requests;

use App\Support\SiteContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'profil' => ['required', Rule::in(app(SiteContent::class)->contactProfiles())],
            'nom' => ['required', 'string', 'max:120'],
            'entreprise' => ['nullable', 'string', 'max:120'],
            'tel' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:190'],
            'objet' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:5000'],
            'consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Renseignez votre nom.',
            'tel.required' => 'Renseignez votre téléphone.',
            'email.required' => 'Renseignez votre adresse e-mail.',
            'email.email' => 'L’adresse e-mail semble incorrecte.',
            'message.required' => 'Écrivez votre message.',
            'consent.accepted' => 'Merci d’accepter l’utilisation de vos informations.',
        ];
    }
}
