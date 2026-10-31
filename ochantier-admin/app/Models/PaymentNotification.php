<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification financière envoyée (promoteur, ouvrier, équipe finance).
 */
class PaymentNotification extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['type', 'recipient', 'channel', 'sent_at', 'content', 'status'];

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
}
