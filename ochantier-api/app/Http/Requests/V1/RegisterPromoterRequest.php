<?php

namespace App\Http\Requests\V1;

use App\Enums\AccountType;
use App\Enums\AppProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inscription d’un promoteur depuis l’Application Promoteur : le compte reste en attente de vérification KYC.
 */
class RegisterPromoterRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $company = fn (): bool => $this->input('account_type') !== AccountType::Individual->value;

        return [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'name' => ['required', 'string', 'max:160'],
            'legal_form' => [Rule::requiredIf($company), 'nullable', 'string', 'max:40'],
            'rccm' => [Rule::requiredIf($company), 'nullable', 'string', 'max:60'],
            'ncc' => ['nullable', 'string', 'max:40'],
            'sector' => ['nullable', 'string', 'max:120'],
            'head_office' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:120'],
            'representative' => ['required', 'string', 'max:120'],
            'representative_role' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', 'string', new PhoneRule(AppProfile::Promoter)],
            'email' => ['required', 'email', 'max:160', Rule::unique('promoters', 'email')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
        ];
    }
}
