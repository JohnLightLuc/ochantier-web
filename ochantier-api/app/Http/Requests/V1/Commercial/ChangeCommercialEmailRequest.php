<?php

namespace App\Http\Requests\V1\Commercial;

use App\Enums\UserType;
use App\Support\OneTimePassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Changer d’adresse e-mail » du commercial : la nouvelle adresse reçoit un code, puis est enregistrée une fois le
 * code confirmé.
 */
class ChangeCommercialEmailRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'email:rfc', 'max:160',
                Rule::unique('users', 'email')->where('user_type', UserType::Commercial->value)->whereNull('deleted_at')->ignore($this->user()->getKey()),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && mb_strtolower($value) === mb_strtolower((string) $this->user()->email)) {
                        $fail('C’est déjà votre adresse e-mail.');
                    }
                },
            ],
            'code' => [$this->routeIs('*.verify') ? 'required' : 'prohibited', 'digits:'.OneTimePassword::length()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Indiquez votre nouvelle adresse e-mail.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.max' => 'L’adresse e-mail ne doit pas dépasser 160 caractères.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre commercial.',
            'code.required' => 'Saisissez le code reçu par e-mail.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
