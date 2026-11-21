<?php

namespace App\Http\Requests\V1\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * « Modifier mes informations » du commercial : prénom et nom. Une nouvelle adresse e-mail (relevés de commissions)
 * se confirme par un code (POST commercial/profile/email) ; l’adresse actuelle renvoyée par le formulaire est acceptée.
 */
class UpdateCommercialProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (mb_strtolower((string) $value) !== mb_strtolower((string) $this->user()->email)) {
                    $fail('Confirmez la nouvelle adresse e-mail avec le code qui lui est envoyé.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Indiquez votre prénom.',
            'last_name.required' => 'Indiquez votre nom.',
            'first_name.max' => 'Le prénom ne doit pas dépasser 80 caractères.',
            'last_name.max' => 'Le nom ne doit pas dépasser 80 caractères.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['first_name', 'last_name', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => preg_replace('/\s+/', ' ', trim($this->input($field)))]);
            }
        }
    }
}
