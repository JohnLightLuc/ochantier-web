<?php

namespace App\Http\Requests\V1;

use App\Support\Notifications\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Canaux et alertes à activer ou désactiver ; seuls les interrupteurs envoyés changent.
 */
class NotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channels' => ['sometimes', 'array:push,sms,email'],
            'channels.*' => ['boolean'],
            'alerts' => ['sometimes', 'array:'.implode(',', array_keys(NotificationPreferences::alertsFor($this->user())))],
            'alerts.*' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (($this->input('channels.email') === true || $this->input('channels.email') === 1) && blank($this->user()->email)) {
                    $validator->errors()->add('channels.email', 'Ajoutez une adresse e-mail pour recevoir les notifications par e-mail.');
                }

                if (! $this->has('channels') && ! $this->has('alerts')) {
                    $validator->errors()->add('channels', 'Indiquez au moins un canal ou une alerte à modifier.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channels.array' => 'Canal inconnu : push, sms ou email.',
            'alerts.array' => 'Alerte inconnue : '.implode(', ', array_keys(NotificationPreferences::alertsFor($this->user()))).'.',
            'channels.*.boolean' => 'Valeur attendue : true ou false.',
            'alerts.*.boolean' => 'Valeur attendue : true ou false.',
        ];
    }
}
