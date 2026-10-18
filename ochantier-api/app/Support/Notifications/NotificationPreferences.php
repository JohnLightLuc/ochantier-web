<?php

namespace App\Support\Notifications;

use App\Enums\PreferenceChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\PhoneNumber;

/**
 * Préférences de notification (« Notifications ») : canaux (push, SMS, e-mail) et types d’alertes.
 *
 * Les canaux sont enregistrés dans la catégorie « canal » ; une alerte est enregistrée pour chaque canal avec la
 * même valeur. Un message est envoyé sur un canal si ce canal et le type d’alerte sont activés tous les deux.
 * Sans préférence enregistrée : push et SMS activés, e-mail désactivé, toutes les alertes activées.
 */
class NotificationPreferences
{
    public const CHANNEL_CATEGORY = 'canal';

    /**
     * Types d’alertes de l’application Promoteur : code => [libellé, précision].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PROMOTER_ALERTS = [
        'offres' => ['Offres et propositions', 'Ouvriers trouvés, offres reçues'],
        'paiements' => ['Paiements', 'Échéances, retards, reçus'],
        'pointages' => ['Pointages', 'Arrivées, départs, absences'],
    ];

    private const DEFAULT_CHANNELS = ['push' => true, 'sms' => true, 'email' => false];

    /**
     * @return array{channels: list<array<string, mixed>>, alerts: list<array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $stored = NotificationPreference::query()->where('user_id', $user->getKey())->get()
            ->mapWithKeys(fn (NotificationPreference $preference) => [$preference->category.'.'.$preference->channel->value => $preference->enabled]);
        $phone = PhoneNumber::of($user);

        $channels = [
            ['key' => 'push', 'label' => 'Notifications push', 'hint' => 'Sur ce téléphone', 'available' => true],
            ['key' => 'sms', 'label' => 'SMS', 'hint' => $phone?->formatted() ?? 'Aucun numéro', 'available' => $phone !== null],
            ['key' => 'email', 'label' => 'E-mail', 'hint' => $user->email ?? 'Non renseigné', 'available' => filled($user->email)],
        ];

        return [
            'channels' => array_map(fn (array $channel) => [
                ...$channel,
                'enabled' => $channel['available'] && ($stored[self::CHANNEL_CATEGORY.'.'.$channel['key']] ?? self::DEFAULT_CHANNELS[$channel['key']]),
            ], $channels),
            'alerts' => array_map(fn (string $key, array $alert) => [
                'key' => $key,
                'label' => $alert[0],
                'hint' => $alert[1],
                'enabled' => $stored[$key.'.push'] ?? true,
            ], array_keys(self::PROMOTER_ALERTS), self::PROMOTER_ALERTS),
        ];
    }

    /**
     * @param  array<string, bool>  $channels
     * @param  array<string, bool>  $alerts
     */
    public function update(User $user, array $channels, array $alerts): void
    {
        $rows = [];

        foreach ($channels as $channel => $enabled) {
            $rows[] = ['user_id' => $user->getKey(), 'category' => self::CHANNEL_CATEGORY, 'channel' => $channel, 'enabled' => (bool) $enabled];
        }

        foreach ($alerts as $category => $enabled) {
            foreach (PreferenceChannel::cases() as $channel) {
                $rows[] = ['user_id' => $user->getKey(), 'category' => $category, 'channel' => $channel->value, 'enabled' => (bool) $enabled];
            }
        }

        if ($rows !== []) {
            NotificationPreference::query()->upsert($rows, ['user_id', 'category', 'channel'], ['enabled']);
        }
    }
}
