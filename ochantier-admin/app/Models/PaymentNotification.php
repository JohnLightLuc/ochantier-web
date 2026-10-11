<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification financière envoyée (promoteur, ouvrier, équipe finance).
 */
#[Fillable(['type', 'recipient', 'channel', 'sent_at', 'content', 'status'])]
class PaymentNotification extends Model
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
}
