<?php

namespace App\Http\Requests\V1\Promoter;

use App\Support\Promoter\PromoterClaims;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Réclamation déposée dans l’application Promoteur.
 */
class ClaimRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_map(fn ($category) => $category->value, PromoterClaims::CATEGORIES))],
            'subject' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'site_id' => ['nullable', 'uuid'],
            'assignment_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.required' => 'Choisissez une catégorie.',
            'category.in' => 'Catégorie inconnue.',
            'subject.required' => 'Indiquez l’objet de votre réclamation.',
            'subject.min' => 'L’objet est trop court.',
            'description.required' => 'Décrivez votre réclamation.',
            'description.min' => 'Votre description est trop courte.',
        ];
    }
}
