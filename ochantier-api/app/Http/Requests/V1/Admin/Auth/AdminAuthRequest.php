<?php

namespace App\Http\Requests\V1\Admin\Auth;

use App\Support\OneTimePassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Base des écrans de connexion du Back Office : messages en français, e-mail professionnel et mot de passe.
 */
abstract class AdminAuthRequest extends FormRequest
{
    /**
     * E-mail saisi, en minuscules et sans espaces.
     */
    public function email(): string
    {
        return mb_strtolower(trim((string) $this->input('email')));
    }

    /**
     * @return array<int, mixed>
     */
    protected function emailRules(): array
    {
        return ['required', 'email', 'max:160'];
    }

    /**
     * @return array<int, mixed>
     */
    protected function codeRules(): array
    {
        return ['required', 'digits:'.OneTimePassword::length()];
    }

    /**
     * Nouveau mot de passe d’un compte administratif : longueur minimale (12 par défaut), majuscule, minuscule,
     * chiffre et symbole, saisi deux fois.
     *
     * @return array<int, mixed>
     */
    protected function newPasswordRules(): array
    {
        return ['required', 'string', 'max:128', 'confirmed', Password::min(self::passwordMinLength())->mixedCase()->numbers()->symbols()];
    }

    /**
     * Appareil à l’origine de la connexion (navigateur décrit par le Back Office).
     *
     * @return array<string, array<int, string>>
     */
    protected function deviceRules(): array
    {
        return [
            'device_name' => ['nullable', 'string', 'max:120'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $length = self::passwordMinLength();

        return [
            'required' => 'Ce champ est obligatoire.',
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email' => 'Saisissez une adresse e-mail valide.',
            'password.required' => 'Saisissez votre mot de passe.',
            'code.required' => 'Saisissez le code reçu.',
            'code.digits' => 'Le code doit contenir '.OneTimePassword::length().' chiffres.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.min' => "Le mot de passe doit contenir au moins {$length} caractères.",
            'password.mixed' => 'Le mot de passe doit contenir au moins une majuscule et une minuscule.',
            'password.numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
            'password.symbols' => 'Le mot de passe doit contenir au moins un symbole.',
            'max' => 'Ce champ est trop long.',
        ];
    }

    private static function passwordMinLength(): int
    {
        return max(8, (int) config('auth.admin.password_min_length'));
    }
}
