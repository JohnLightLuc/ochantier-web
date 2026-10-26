<?php

namespace App\Http\Requests\V1\Commercial;

use App\Enums\UserType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Modifier mes informations » du commercial : prénom, nom et adresse e-mail (relevés de commissions).
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
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:160', Rule::unique('users', 'email')->where('user_type', UserType::Commercial->value)->whereNull('deleted_at')->ignore($this->user()->getKey())],
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
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre commercial.',
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
