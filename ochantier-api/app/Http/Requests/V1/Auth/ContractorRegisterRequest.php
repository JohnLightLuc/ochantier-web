<?php

namespace App\Http\Requests\V1\Auth;

/**
 * Inscription d’un entrepreneur ou technicien (« Créer un compte ») : téléphone ou e-mail, mot de passe saisi deux fois,
 * conditions d’utilisation et traitement des données pour la vérification du profil.
 */
class ContractorRegisterRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->contactRules(),
            'password' => $this->newPasswordRules(),
            'accept_terms' => ['accepted'],
            'accept_data_processing' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'accept_terms.accepted' => 'Acceptez les conditions générales d’utilisation.',
            'accept_data_processing.accepted' => 'Acceptez le traitement de vos données personnelles pour la vérification de votre profil.',
        ];
    }
}
