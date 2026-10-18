<?php

namespace App\Models;

use App\Enums\BroadcastCampaignStatus;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Campagne de message envoyée à une audience ciblée.
 */
#[Fillable([
    'title', 'audience_filter', 'channel', 'message', 'status', 'created_by_admin_id', 'approved_by_admin_id',
    'scheduled_at', 'sent_at', 'recipients_count', 'delivered_count',
])]
class BroadcastCampaign extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience_filter' => 'array',
            'channel' => NotificationChannel::class,
            'status' => BroadcastCampaignStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function approvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
