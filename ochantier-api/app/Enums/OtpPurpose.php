<?php

namespace App\Enums;

/**
 * Valeurs de `purpose` pour la table `otp_codes`.
 */
enum OtpPurpose: string
{
    case Activation = 'activation';
    case Login = 'login';
    case PasswordReset = 'password_reset';
    case SensitiveAction = 'sensitive_action';
    case PhoneChange = 'phone_change';
    case WorkerPhoneCheck = 'worker_phone_check';

    public function label(): string
    {
        return match ($this) {
            self::Activation => 'Activation',
            self::Login => 'Connexion',
            self::PasswordReset => 'Réinitialisation du mot de passe',
            self::SensitiveAction => 'Action sensible',
            self::PhoneChange => 'Changement de numéro',
            self::WorkerPhoneCheck => 'Vérification du numéro de l’ouvrier',
        };
    }
}
