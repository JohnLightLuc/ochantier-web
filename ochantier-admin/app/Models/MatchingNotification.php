<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification envoyée par le module Matching.
 */
class MatchingNotification extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['type', 'recipient', 'channel', 'sent_at', 'content', 'action', 'status'];

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
