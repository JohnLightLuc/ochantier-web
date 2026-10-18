<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Pilote de développement : écrit les SMS dans le journal au lieu de les envoyer.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS', ['to' => $phone, 'message' => $message]);
    }
}
