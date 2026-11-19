<?php

namespace App\Support\Admin;

use App\Enums\Priority;
use App\Models\Admin;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Support\Notifications\NotificationInbox;
use App\Support\Notifications\NotificationPreferences;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Notification écrite par le Back office pour un utilisateur des applications mobiles : inscrite dans son centre de
 * notifications, puis envoyée en push (et par SMS si demandé) selon ses préférences, comme les notifications métier.
 */
class AdminNotifications
{
    /**
     * Code d’événement des notifications du Back office (centre de notifications, données du push).
     */
    public const EVENT = 'admin.message';

    public const TITLE_MAX = 120;

    public const BODY_MAX = 500;

    public function __construct(private NotificationInbox $inbox, private NotificationPreferences $preferences) {}

    /**
     * Envoie la notification. Avec un client_uuid déjà reçu pour ce destinataire, renvoie la notification existante sans
     * renvoyer de push ni de SMS (double envoi depuis le Back office).
     *
     * @return array{notification: Notification, created: bool, push_devices: int, sms: bool}
     */
    public function send(User $user, string $title, string $body, bool $sms, ?string $clientUuid, Admin $admin, Request $request): array
    {
        if (! $user->canSignIn()) {
            throw ValidationException::withMessages(['recipient_id' => 'Ce compte n’est pas actif : la notification ne peut pas lui être envoyée.']);
        }

        $dedup = self::EVENT.':'.($clientUuid ?? (string) Str::uuid());
        $created = $this->inbox->send($user, self::EVENT, ['tab' => null, 'alert' => null, 'priority' => Priority::Normale, 'sms' => $sms], $title, $body, dedup: $dedup);
        $notification = Notification::query()->where('dedup_key', $dedup.':'.$user->getKey())->firstOrFail();

        if (! $created) {
            return ['notification' => $notification, 'created' => false, 'push_devices' => 0, 'sms' => false];
        }

        $pushDevices = $this->preferences->allows($user, 'push')
            ? DeviceToken::query()->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())->whereNull('revoked_at')->count()
            : 0;
        $smsSent = $sms && PhoneNumber::of($user) !== null && $this->preferences->allows($user, 'sms');

        AdminAudit::record($admin, 'notifications', 'notification.sent', $request, $notification, after: [
            'recipient_user_id' => $user->getKey(),
            'title' => $title,
            'body' => $body,
            'send_sms' => $sms,
            'push_devices' => $pushDevices,
        ]);

        return ['notification' => $notification, 'created' => true, 'push_devices' => $pushDevices, 'sms' => $smsSent];
    }
}
