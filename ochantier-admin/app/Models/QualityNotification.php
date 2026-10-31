<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification envoyée par Avis & litiges (avis, droit de réponse, réclamation, litige, médiation, décision, SLA).
 */
class QualityNotification extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['sent_at', 'recipient', 'channel', 'content', 'expected_action', 'reference', 'status'];

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

    public static function send(string $recipient, string $channel, string $content, string $expected, ?string $reference = null): self
    {
        return self::create(['sent_at' => now(), 'recipient' => $recipient, 'channel' => $channel, 'content' => $content, 'expected_action' => $expected, 'reference' => $reference, 'status' => 'Envoyée']);
    }
}
