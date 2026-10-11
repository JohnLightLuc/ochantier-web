<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification envoyée par le module Matching.
 */
#[Fillable(['type', 'recipient', 'channel', 'sent_at', 'content', 'action', 'status'])]
class MatchingNotification extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Enregistre une notification envoyée par le Matching.
     */
    public static function send(string $type, string $recipient, string $channel, string $content, string $action): self
    {
        return static::create(['type' => $type, 'recipient' => $recipient, 'channel' => $channel, 'sent_at' => now(), 'content' => $content, 'action' => $action, 'status' => 'Envoyée']);
    }
}
