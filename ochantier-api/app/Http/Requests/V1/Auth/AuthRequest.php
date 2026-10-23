<?php

namespace App\Http\Requests\V1\Auth;

use App\Enums\MessageChannel;
use App\Http\Requests\V1\PhoneFields;
use App\Support\OneTimePassword;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Base des écrans d’authentification : messages en français, contact (téléphone ou e-mail) et mot de passe.
 */
abstract class AuthRequest extends FormRequest
{
    use PhoneFields;

    /**
     * Destination normalisée du code : numéro E.164 ou e-mail en minuscules.
     *
     * @return array{0: MessageChannel, 1: string}
     */
    public function contact(): array
    {
        $recipient = $this->recipient();

        return $recipient instanceof PhoneNumber ? [MessageChannel::Sms, $recipient->e164()] : [MessageChannel::Email, $recipient];
    }

    /**
     * Destinataire du code : le numéro saisi (dial_code et phone_number), sinon l’e-mail en minuscules.
     */
    public function recipient(): PhoneNumber|string
    {
        return $this->phoneNumber() ?? mb_strtolower(trim((string) $this->input('email')));
    }

    /**
     * Critères de recherche du compte (User::findByContact) : numéro E.164, sinon e-mail.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function contactLookup(): array
    {
        $phone = $this->phoneNumber();

        return [$phone?->e164(), $phone ? null : $this->recipient()];
    }

    /**
     * Téléphone (dial_code et phone_number) ou e-mail, l’un des deux étant obligatoire.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function contactRules(): array
    {
        return [
            ...$this->phoneRules(['required_without:email']),
            'email' => ['required_without:phone_number', 'nullable', 'email', 'max:160'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function codeRules(): array
    {
        return ['required', 'digits:'.OneTimePassword::length()];
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
            ...$this->phoneMessages(),
            'required' => 'Ce champ est obligatoire.',
            'required_without' => 'Saisissez un numéro de téléphone ou une adresse e-mail.',
            'required_if' => 'Ce champ est obligatoire.',
            'email' => 'Saisissez une adresse e-mail valide.',
            'code.digits' => 'Saisissez les '.OneTimePassword::length().' chiffres du code.',
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
