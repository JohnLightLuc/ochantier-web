<?php

namespace App\Support\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

/**
 * Envoi par Firebase Cloud Messaging (PUSH_DRIVER=fcm). Les jetons que FCM déclare invalides ou inconnus (application
 * désinstallée, jeton expiré) sont révoqués. Un échec d’envoi est journalisé sans interrompre l’action en cours : la
 * notification reste dans le centre de notifications.
 */
class FcmPushGateway implements PushGateway
{
    /**
     * Nombre maximal de jetons par envoi groupé accepté par FCM.
     */
    public const BATCH_SIZE = 500;

    public function __construct(private Messaging $messaging) {}

    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if ($tokens === []) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData(self::data($data))
            ->withDefaultSounds()
            ->withHighestPossiblePriority();

        foreach (array_chunk($tokens, self::BATCH_SIZE) as $batch) {
            try {
                $this->handle($this->messaging->sendMulticast($message, $batch), $title);
            } catch (Throwable $exception) {
                Log::warning('Push FCM non envoyé', ['tokens' => count($batch), 'title' => $title, 'error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * Données transmises à l’application : FCM n’accepte que des chaînes. Les tableaux (cible de navigation…) sont
     * encodés en JSON, les valeurs nulles retirées.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function data(array $data): array
    {
        $values = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $values[(string) $key] = match (true) {
                is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $values;
    }

    private function handle(MulticastSendReport $report, string $title): void
    {
        $stale = array_values(array_unique([...$report->invalidTokens(), ...$report->unknownTokens()]));

        if ($stale !== []) {
            DeviceToken::query()->whereIn('push_token', $stale)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        $errors = $report->failures()->count() - count($stale);

        if ($errors > 0) {
            Log::warning('Push FCM partiellement échoué', [
                'title' => $title,
                'failures' => $errors,
                'error' => $report->failures()->getItems()[0]->error()?->getMessage(),
            ]);
        }
    }
}
