<?php

namespace App\Support\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Push\PushGateway;
use App\Support\Sms\SmsGateway;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Centre de notifications des applications mobiles : chaque notification est inscrite pour son destinataire (canal
 * in_app), puis envoyée en push et, si demandé, par SMS selon ses préférences. Liste groupée par période
 * (Aujourd’hui, Cette semaine, Plus anciennes), compteurs par onglet et lecture.
 */
class NotificationInbox
{
    /**
     * Notifications affichées dans le centre (les plus récentes) ; les compteurs de non lues portent sur tout
     * l’historique.
     */
    public const CENTER_LIMIT = 100;

    public function __construct(
        private NotificationPreferences $preferences,
        private PushGateway $push,
        private SmsGateway $sms,
    ) {}

    /**
     * Inscrit la notification ; $dedup évite d’envoyer deux fois le même événement à ce destinataire. Renvoie false
     * si elle avait déjà été envoyée.
     *
     * @param  array{tab: string, alert: string|null, priority: Priority, sms: bool}  $definition
     * @param  array<string, mixed>  $data  Données affichées et écran à ouvrir (target).
     */
    public function send(User $user, string $event, array $definition, string $title, string $body, array $data = [], ?Model $related = null, ?string $dedup = null): bool
    {
        $key = $dedup ? $dedup.':'.$user->getKey() : null;

        if ($key && Notification::query()->where('dedup_key', $key)->exists()) {
            return false;
        }

        Notification::query()->create([
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'event_code' => $event,
            'channel' => NotificationChannel::InApp,
            'title' => $title,
            'body' => $body,
            'data' => ['tab' => $definition['tab'], ...$data],
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'priority' => $definition['priority'],
            'status' => NotificationStatus::Livree,
            'sent_at' => now(),
            'delivered_at' => now(),
            'dedup_key' => $key,
        ]);

        if ($this->preferences->allows($user, 'push', $definition['alert'])) {
            $tokens = DeviceToken::query()->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())->whereNull('revoked_at')->pluck('push_token')->all();

            if ($tokens !== []) {
                $this->push->send($tokens, $title, $body, ['event' => $event, 'target' => $data['target'] ?? null, 'unread_count' => $this->unreadCount($user)]);
            }
        }

        if ($definition['sms'] && ($phone = PhoneNumber::of($user)) && $this->preferences->allows($user, 'sms', $definition['alert'])) {
            $this->sms->send($phone->e164(), 'O’CHANTIER : '.$title.'. '.$body);
        }

        return true;
    }

    /**
     * Centre de notifications : groupes par période, filtrés par onglet.
     *
     * @param  array<string, string>  $tabs  clé => libellé.
     * @param  Closure(Notification, string): bool  $matches  La notification appartient-elle à l’onglet ?
     * @param  Closure(Notification): array<string, mixed>  $extra  Champs ajoutés à chaque notification (actions, tag…).
     * @param  list<string>  $countless  Onglets sans compteur de non lues (Toutes, Non lues).
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function center(User $user, string $filter, array $tabs, Closure $matches, ?Closure $extra = null, array $countless = []): array
    {
        $notifications = $this->query($user)->latest('created_at')->limit(self::CENTER_LIMIT)->get();
        $unread = $notifications->whereNull('read_at');
        $unreadCount = $this->unreadCount($user);
        $today = CarbonImmutable::today();
        $list = $notifications->filter(fn (Notification $notification) => $matches($notification, $filter));

        return [
            'data' => collect(['today' => 'AUJOURD’HUI', 'week' => 'CETTE SEMAINE', 'older' => 'PLUS ANCIENNES'])
                ->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'items' => $list->filter(fn (Notification $notification) => $this->group($notification, $today) === $key)
                        ->map(fn (Notification $notification) => [...$this->item($notification), ...($extra ? $extra($notification) : [])])
                        ->values()
                        ->all(),
                ])
                ->filter(fn (array $group) => $group['items'] !== [])
                ->values()
                ->all(),
            'meta' => [
                'unread' => $unreadCount,
                'unread_count' => $unreadCount,
                'summary' => $unreadCount === 0 ? 'Tout est lu' : $unreadCount.' non lue'.($unreadCount > 1 ? 's' : ''),
                'tabs' => collect($tabs)->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'unread' => in_array($key, $countless, true) ? null : $unread->filter(fn (Notification $notification) => $matches($notification, $key))->count(),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * Notifications non lues sur tout l’historique (badge de la cloche), sans charger les lignes.
     */
    public function unreadCount(User $user): int
    {
        return $this->query($user)->whereNull('read_at')->count();
    }

    /**
     * Marque lue une notification ($id) ou toutes ; renvoie le nombre de notifications marquées.
     */
    public function markRead(User $user, ?string $id = null): int
    {
        return $this->query($user)->whereNull('read_at')->when($id, fn ($query) => $query->whereKey($id))->update(['read_at' => now()]);
    }

    /**
     * @return Builder<Notification>
     */
    private function query(User $user): Builder
    {
        return Notification::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->where('channel', NotificationChannel::InApp);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Notification $notification): array
    {
        $at = CarbonImmutable::parse($notification->created_at);

        return [
            'id' => $notification->id,
            'event' => $notification->event_code,
            'tab' => $notification->data['tab'] ?? null,
            'title' => $notification->title,
            'body' => $notification->body,
            'time' => $this->time($at),
            'created_at' => $at->toIso8601String(),
            'unread' => $notification->read_at === null,
            'target' => $notification->data['target'] ?? null,
        ];
    }

    private function group(Notification $notification, CarbonImmutable $today): string
    {
        $date = CarbonImmutable::parse($notification->created_at)->setTimezone(config('app.timezone'))->startOfDay();

        return match (true) {
            $date->greaterThanOrEqualTo($today) => 'today',
            $date->greaterThan($today->subDays(7)) => 'week',
            default => 'older',
        };
    }

    private function time(CarbonImmutable $at): string
    {
        $minutes = (int) $at->diffInMinutes(now());
        $local = $at->setTimezone(config('app.timezone'));

        return match (true) {
            $minutes < 1 => 'À l’instant',
            $minutes < 60 => 'Il y a '.$minutes.' min',
            $local->isToday() => 'Il y a '.intdiv($minutes, 60).' h',
            $local->isYesterday() => 'Hier · '.$local->format('H:i'),
            default => $local->locale('fr')->translatedFormat('j M · H:i'),
        };
    }
}
