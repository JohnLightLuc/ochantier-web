<?php

namespace App\Http\Requests\V1\Admin\Notification;

use App\Enums\UserType;
use App\Support\Admin\AdminNotifications;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Notification envoyée par le Back office à un utilisateur des applications mobiles (promoteur ou commercial).
 */
class SendNotificationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', Rule::exists('users', 'id')
                ->whereIn('user_type', array_map(fn (UserType $type) => $type->value, AdminNotifications::RECIPIENT_TYPES))
                ->whereNull('deleted_at')],
            'title' => ['required', 'string', 'min:2', 'max:'.AdminNotifications::TITLE_MAX],
            'body' => ['required', 'string', 'min:2', 'max:'.AdminNotifications::BODY_MAX],
            'send_sms' => ['sometimes', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Choisissez le destinataire.',
            'user_id.exists' => 'Destinataire introuvable : seuls les promoteurs et les commerciaux reçoivent des notifications.',
            'title.required' => 'Indiquez le titre de la notification.',
            'title.max' => 'Le titre ne doit pas dépasser '.AdminNotifications::TITLE_MAX.' caractères.',
            'body.required' => 'Indiquez le message.',
            'body.max' => 'Le message ne doit pas dépasser '.AdminNotifications::BODY_MAX.' caractères.',
        ];
    }

    public function title(): string
    {
        return trim((string) $this->input('title'));
    }

    public function body(): string
    {
        return trim((string) $this->input('body'));
    }
}
