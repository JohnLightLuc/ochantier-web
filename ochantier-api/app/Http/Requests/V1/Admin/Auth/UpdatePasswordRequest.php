<?php

namespace App\Http\Requests\V1\Admin\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Changement du mot de passe (y compris le remplacement du mot de passe provisoire).
 */
class UpdatePasswordRequest extends AdminAuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => $this->newPasswordRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hash = $this->user()?->password_hash;

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $hash || ! Hash::check((string) $this->input('current_password'), $hash)) {
                    $validator->errors()->add('current_password', 'Mot de passe actuel incorrect.');
                } elseif (Hash::check((string) $this->input('password'), $hash)) {
                    $validator->errors()->add('password', 'Choisissez un mot de passe différent de l’actuel.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'current_password.required' => 'Saisissez votre mot de passe actuel.'];
    }
}
