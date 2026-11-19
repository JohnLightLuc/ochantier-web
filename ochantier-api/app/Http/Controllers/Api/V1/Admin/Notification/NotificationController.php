<?php

namespace App\Http\Controllers\Api\V1\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Admin\Notification\SendNotificationRequest;
use App\Support\Admin\AdminNotifications;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;

/**
 * Notifications envoyées par le Back office aux utilisateurs des applications mobiles.
 */
class NotificationController extends Controller
{
    public function __construct(private AdminNotifications $notifications) {}

    /**
     * Inscrit la notification dans le centre de notifications du destinataire et l’envoie en push (SMS en option).
     * 201 à l’envoi ; 200 avec la notification existante pour un client_uuid déjà reçu.
     */
    public function store(SendNotificationRequest $request): JsonResponse
    {
        $recipient = $request->recipient();
        $user = $recipient->user;
        $result = $this->notifications->send($user, $request->title(), $request->body(), $request->boolean('send_sms'), $request->input('client_uuid'), $request->user(), $request);
        $notification = $result['notification'];

        return ApiResponse::success([
            'id' => $notification->id,
            'event' => $notification->event_code,
            'title' => $notification->title,
            'body' => $notification->body,
            'recipient' => [
                'type' => ['value' => $request->input('recipient_type'), 'label' => $user->user_type->label()],
                'id' => $recipient->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'phone' => PhoneNumber::of($user)?->toArray()['phone'],
            ],
            'push' => ['sent' => $result['push_devices'] > 0, 'devices' => $result['push_devices']],
            'sms' => ['sent' => $result['sms']],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ], $result['created'] ? 'Notification envoyée.' : 'Notification déjà envoyée.', $result['created'] ? 201 : 200);
    }
}
