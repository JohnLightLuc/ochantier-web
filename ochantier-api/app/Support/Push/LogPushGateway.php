<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Log;

/**
 * Pilote de développement : écrit les notifications push dans le journal au lieu de les envoyer.
 */
class LogPushGateway implements PushGateway
{
    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        Log::info('Push', ['tokens' => count($tokens), 'title' => $title, 'body' => $body, 'data' => $data]);
    }
}
