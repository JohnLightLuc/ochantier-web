<?php

namespace App\Http\Requests\V1\Admin\Notification;

use App\Models\CommercialProfile;
use App\Models\PromoterProfile;
use App\Models\User;
use App\Support\Admin\AdminNotifications;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Notification envoyée par le Back office à un utilisateur des applications mobiles. Le destinataire est désigné par sa
 * fiche du Back office : recipient_type (promoter, commercial) et recipient_id (id de /admin/promoters/{id} ou
 * /admin/commercials/{id}).
 */
class SendNotificationRequest extends FormRequest
{
    /**
     * Type de destinataire => table de sa fiche.
     *
     * @var array<string, class-string<PromoterProfile|CommercialProfile>>
     */
    public const RECIPIENTS = ['promoter' => PromoterProfile::class, 'commercial' => CommercialProfile::class];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $model = self::RECIPIENTS[$this->input('recipient_type')] ?? null;

        return [
            'recipient_type' => ['required', Rule::in(array_keys(self::RECIPIENTS))],
            'recipient_id' => ['required', 'uuid', ...($model ? [Rule::exists((new $model)->getTable(), 'id')->whereNull('deleted_at')] : [])],
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
            'recipient_type.required' => 'Choisissez le type de destinataire.',
            'recipient_type.in' => 'Type de destinataire attendu : promoter ou commercial.',
            'recipient_id.required' => 'Choisissez le destinataire.',
            'recipient_id.exists' => 'Destinataire introuvable.',
            'title.required' => 'Indiquez le titre de la notification.',
            'title.max' => 'Le titre ne doit pas dépasser '.AdminNotifications::TITLE_MAX.' caractères.',
            'body.required' => 'Indiquez le message.',
            'body.max' => 'Le message ne doit pas dépasser '.AdminNotifications::BODY_MAX.' caractères.',
        ];
    }

    /**
     * Fiche du destinataire (promoteur ou commercial).
     */
    public function recipient(): PromoterProfile|CommercialProfile
    {
        return self::RECIPIENTS[$this->input('recipient_type')]::query()->with('user')->findOrFail($this->input('recipient_id'));
    }

    /**
     * Compte utilisateur qui reçoit la notification.
     */
    public function recipientUser(): User
    {
        return $this->recipient()->user;
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
