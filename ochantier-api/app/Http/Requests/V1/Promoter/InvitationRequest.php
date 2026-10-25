<?php

namespace App\Http\Requests\V1\Promoter;

use App\Enums\OrganizationRole;
use App\Http\Requests\V1\PhoneFields;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * « Inviter un utilisateur » : nom, rôle et canal (SMS avec dial_code/phone_number, ou e-mail).
 */
class InvitationRequest extends FormRequest
{
    use PhoneFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'channel' => ['required', Rule::in(['sms', 'email'])],
            ...$this->phoneRules(['exclude_unless:channel,sms', 'required']),
            'email' => ['exclude_unless:channel,email', 'required', 'email:rfc', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->phoneMessages(),
            'first_name.required' => 'Indiquez le nom de la personne à inviter.',
            'first_name.max' => 'Le prénom ne doit pas dépasser 80 caractères.',
            'last_name.max' => 'Le nom ne doit pas dépasser 80 caractères.',
            'role.required' => 'Choisissez un rôle.',
            'role.enum' => 'Rôle inconnu.',
            'channel.required' => 'Choisissez d’inviter par SMS ou par e-mail.',
            'channel.in' => 'Choisissez d’inviter par SMS ou par e-mail.',
            'email.required' => 'Saisissez une adresse e-mail.',
            'email.email' => 'Adresse e-mail invalide.',
        ];
    }

    /**
     * @return array{first_name: string, last_name: string|null, role: string, channel: string, phone: PhoneNumber|null, email: string|null}
     */
    public function invitation(): array
    {
        return [
            'first_name' => (string) $this->input('first_name'),
            'last_name' => $this->input('last_name'),
            'role' => (string) $this->input('role'),
            'channel' => (string) $this->input('channel'),
            'phone' => $this->input('channel') === 'sms' ? $this->phoneNumber() : null,
            'email' => $this->input('channel') === 'email' ? (string) $this->input('email') : null,
        ];
    }
}
