<?php

namespace App\Http\Requests\V1\Auth;

use App\Enums\MessageChannel;
use App\Http\Requests\V1\PhoneRule;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Base des écrans d’authentification : messages en français, contact (téléphone ou e-mail) et mot de passe.
 */
abstract class AuthRequest extends FormRequest
{
    /**
     * Destination normalisée du code : numéro E.164 ou e-mail en minuscules.
     *
     * @return array{0: MessageChannel, 1: string}
     */
    public function contact(): array
    {
        if ($this->filled('phone')) {
            return [MessageChannel::Sms, Phone::e164((string) $this->input('phone'))];
        }

        return [MessageChannel::Email, mb_strtolower(trim((string) $this->input('email')))];
    }

    /**
     * Téléphone ou e-mail, l’un des deux étant obligatoire.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function contactRules(): array
    {
        return [
            'phone' => ['required_without:email', 'nullable', 'string', new PhoneRule],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:160'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function codeRules(): array
    {
        return ['required', 'digits:6'];
    }

    /**
     * Nouveau mot de passe : 8 caractères minimum, dont un chiffre, saisi deux fois.
     *
     * @return array<int, mixed>
     */
    protected function newPasswordRules(): array
    {
        return ['required', 'string', 'confirmed', Password::min(8)->numbers()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'required_without' => 'Saisissez un numéro de téléphone ou une adresse e-mail.',
            'required_if' => 'Ce champ est obligatoire.',
            'email' => 'Saisissez une adresse e-mail valide.',
            'code.digits' => 'Saisissez les 6 chiffres du code.',
            'accept_terms.accepted' => 'Acceptez les conditions d’utilisation et la politique de confidentialité.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
            'max' => 'Ce champ est trop long.',
            'in' => 'Valeur non autorisée.',
            'enum' => 'Valeur non autorisée.',
        ];
    }
}
