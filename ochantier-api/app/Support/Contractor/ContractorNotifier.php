<?php

namespace App\Support\Contractor;

use App\Enums\Priority;
use App\Models\Contractor;
use App\Support\Notifications\NotificationInbox;

/**
 * Notifications de l’application Entrepreneurs et Techniciens. Inscrites dans le centre de notifications, puis
 * envoyées en push et, pour les décisions importantes, par SMS selon les préférences.
 */
class ContractorNotifier
{
    /**
     * Événement => onglet, alerte des préférences, priorité, SMS et bouton à afficher.
     *
     * @var array<string, array{tab: string, alert: string|null, priority: Priority, sms: bool, cta: string}>
     */
    public const EVENTS = [
        'profile.submitted' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Normale, 'sms' => false, 'cta' => 'Voir mon profil'],
        'account.approved' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Voir les opportunités'],
        'account.corrections' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Corriger mon dossier'],
        'account.rejected' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Corriger mon dossier'],
        'account.suspended' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => 'Contacter l’assistance'],
        'account.reactivated' => ['tab' => 'compte', 'alert' => null, 'priority' => Priority::Haute, 'sms' => true, 'cta' => ''],
    ];

    public function __construct(private NotificationInbox $inbox) {}

    public function notify(Contractor $contractor, string $event, string $title, string $body): void
    {
        $user = $contractor->user;

        if ($user) {
            $this->inbox->send($user, $event, self::EVENTS[$event], $title, $body,
                ['target' => ['type' => 'profile', 'id' => $contractor->id], 'cta' => self::EVENTS[$event]['cta']], $contractor);
        }
    }
}
